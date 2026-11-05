<?php

declare(strict_types=1);

namespace ProtoBlocks\Modules\Scripts;

use ProtoBlocks\API\AdminRestController;
use ProtoBlocks\API\RestAPI;
use ProtoBlocks\ContentModel\Issue;
use ProtoBlocks\ContentModel\Jsonc;
use ProtoBlocks\ContentModel\Registry as ContentModelRegistry;
use ProtoBlocks\ContentModel\SchemaFileWriter;
use ProtoBlocks\ContentModel\SchemaPaths;
use ProtoBlocks\Modules\ModuleInterface;
use ProtoBlocks\Modules\Scripts\Definition\ScriptValidator;
use ProtoBlocks\Modules\Scripts\Sources\PhpDeclarations;
use ProtoBlocks\Modules\Scripts\Sources\ScriptFiles;
use WP_Error;

/**
 * The scripts module (docs/superpowers/specs/2026-11-05-scripts-design.md):
 * head / body-open / body-close snippets declared in
 * `proto-scripts/<slug>/script.jsonc` (+ snippet files) or with
 * proto_script(), printed on the front end by Output.
 *
 * boot() touches no file: the registry loads on first use (the first
 * output hook, or an admin route).
 *
 * Trust (spec §5): writing a script file needs `unfiltered_html` on top of
 * the usual file-write rules, because its content is printed raw. The
 * module's own write route checks it; guardSchemaFiles() applies the same
 * rule to the generic `admin/schema-files/script/<slug>` routes.
 */
final class ScriptsModule implements ModuleInterface
{
    public const ID = 'scripts';
    public const KIND = 'script';

    private static ?self $instance = null;

    private ?Registry $scripts;
    private ?Output $output = null;
    private bool $booted = false;

    /** @var array<string, string> request route => script folder to clean after a schema-files DELETE */
    private array $pendingCleanup = [];

    public function __construct(private readonly ContentModelRegistry $registry, ?Registry $scripts = null)
    {
        $this->scripts = $scripts;
    }

    public function id(): string
    {
        return self::ID;
    }

    public function boot(): void
    {
        if ($this->booted) {
            return;
        }
        $this->booted = true;

        self::registerFileKind();
        add_action(PhpDeclarations::SEAL_HOOK, [PhpDeclarations::class, 'seal'], PhpDeclarations::SEAL_PRIORITY);
        $this->output()->register();
        add_filter('rest_request_before_callbacks', [$this, 'guardSchemaFiles'], 10, 3);
        add_filter('rest_request_after_callbacks', [$this, 'afterSchemaFiles'], 10, 3);
    }

    /**
     * The `script` declaration kind for SchemaFileWriter and the schema-files
     * routes: `proto-scripts/<slug>/script.jsonc`, validated by
     * ScriptValidator before anything is written. Idempotent.
     */
    public static function registerFileKind(): void
    {
        SchemaFileWriter::extendKind(
            self::KIND,
            ScriptFiles::FOLDER,
            ScriptFiles::BASENAME,
            static fn (array $data): array => array_map(
                static fn (array $e): array => ['path' => $e['path'], 'message' => $e['message']],
                ScriptValidator::validate($data)['errors']
            ),
            [
                'slugPattern' => SchemaFileWriter::EXTENDED_SLUG_PATTERN,
                'decode' => static fn (string $text): array => Jsonc::decode($text),
                'template' => static fn (string $slug, string $name): string => ScriptTemplate::render($name),
            ]
        );
    }

    public function scripts(): Registry
    {
        return $this->scripts ??= new Registry();
    }

    public function output(): Output
    {
        return $this->output ??= new Output(fn (): Registry => $this->scripts());
    }

    /** @return list<Issue> */
    public function issues(): array
    {
        return [...$this->scripts()->issues(), ...Output::bodyOpenIssues($this->scripts()->all())];
    }

    /**
     * Why the current user cannot write script files: the inherited reasons
     * (`file_mods`, `multisite`) first, then `unfiltered_html`; null when allowed.
     *
     * @return 'file_mods'|'multisite'|'unfiltered_html'|null
     */
    public static function writeReason(): ?string
    {
        $reason = AdminRestController::writeReason();
        if ($reason !== null) {
            return $reason;
        }

        return current_user_can('unfiltered_html') ? null : 'unfiltered_html';
    }

    public static function unfilteredHtmlError(): WP_Error
    {
        return new WP_Error(
            'proto_blocks_unfiltered_html',
            __('Only users who may post unfiltered HTML can edit scripts.', 'proto-blocks'),
            ['status' => 403]
        );
    }

    /**
     * `rest_request_before_callbacks`: a write or delete on
     * `admin/schema-files/script/<slug>` needs `unfiltered_html`; a delete
     * of a folder SnippetFiles::deletable() accepts is remembered so
     * afterSchemaFiles() can remove the snippet files with it.
     *
     * @param mixed $response
     * @param mixed $handler
     * @param mixed $request
     * @return mixed
     */
    public function guardSchemaFiles($response, $handler, $request)
    {
        $slug = self::schemaFilesSlug($request);
        if ($slug === null || is_wp_error($response)) {
            return $response;
        }
        $method = strtoupper((string) $request->get_method());
        if ($method === 'GET' || $method === 'HEAD' || $method === 'OPTIONS') {
            return $response;
        }
        if (!current_user_can('unfiltered_html')) {
            return self::unfilteredHtmlError();
        }
        if ($method === 'DELETE' && $request->get_param('confirm') === $slug) {
            $existing = $this->writer()->existing(self::KIND, $slug);
            if ($existing !== null && SnippetFiles::deletable(dirname($existing->source))) {
                $this->pendingCleanup[(string) $request->get_route()] = dirname($existing->source);
            }
        }

        return $response;
    }

    /**
     * `rest_request_after_callbacks`: after a successful schema-files DELETE
     * of a script, removes the snippet files and the folder (see guardSchemaFiles()).
     *
     * @param mixed $response
     * @param mixed $handler
     * @param mixed $request
     * @return mixed
     */
    public function afterSchemaFiles($response, $handler, $request)
    {
        if (self::schemaFilesSlug($request) === null) {
            return $response;
        }
        $route = (string) $request->get_route();
        $dir = $this->pendingCleanup[$route] ?? null;
        unset($this->pendingCleanup[$route]);
        if ($dir === null || is_wp_error($response)) {
            return $response;
        }
        $data = is_object($response) && method_exists($response, 'get_data') ? $response->get_data() : $response;
        if (!is_array($data) || !isset($data['deleted'])) {
            return $response;
        }
        try {
            $data['kept'] = $this->snippets()->removeLeftovers($dir);
        } catch (\ProtoBlocks\ContentModel\WriteException) {
            return $response;
        }
        $this->scripts()->invalidate();
        if (is_object($response) && method_exists($response, 'set_data')) {
            $response->set_data($data);
            return $response;
        }

        return $data;
    }

    /** The module the manager booted; null when disabled or not booted. */
    public static function instance(): ?self
    {
        return self::$instance;
    }

    /** @internal Set by Modules\Manager when the module boots. */
    public static function setInstance(?self $module): void
    {
        self::$instance = $module;
    }

    /** The slug of an `admin/schema-files/script/<slug>` request, else null. */
    private static function schemaFilesSlug(mixed $request): ?string
    {
        if (!is_object($request) || !method_exists($request, 'get_route')) {
            return null;
        }
        $pattern = '~^/' . preg_quote(RestAPI::NAMESPACE, '~') . '/' . AdminRestController::BASE . '/schema-files/' . self::KIND . '/([^/]+)/?$~iD';

        return preg_match($pattern, (string) $request->get_route(), $m) === 1 ? $m[1] : null;
    }

    private function writer(): SchemaFileWriter
    {
        return new SchemaFileWriter(SchemaPaths::writableRoots(), AdminRestController::fileWritesAllowed());
    }

    private function snippets(): SnippetFiles
    {
        return new SnippetFiles(SchemaPaths::writableRoots(), AdminRestController::fileWritesAllowed());
    }
}
