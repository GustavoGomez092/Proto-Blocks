<?php

declare(strict_types=1);

namespace ProtoBlocks\Modules\Redirects;

use ProtoBlocks\ContentModel\Issue;
use ProtoBlocks\ContentModel\Registry as ContentModelRegistry;
use ProtoBlocks\ContentModel\SchemaFileWriter;
use ProtoBlocks\Modules\ModuleInterface;
use ProtoBlocks\Modules\Redirects\Definition\GroupValidator;
use ProtoBlocks\Modules\Redirects\Definition\SettingsValidator;
use ProtoBlocks\Modules\Redirects\Sources\GroupFiles;
use ProtoBlocks\Modules\Redirects\Sources\PhpDeclarations;

/**
 * The redirects module (docs/superpowers/specs/2026-11-05-redirects-design.md).
 * Rules and settings are files in the theme (`proto-redirects/`), read by
 * Registry; the database only ever holds hits and logs.
 */
final class RedirectsModule implements ModuleInterface
{
    public const ID = 'redirects';

    /** PHP declarations are accepted until this `init` priority. */
    public const SEAL_PRIORITY = 9;

    private static ?self $instance = null;

    private ?Registry $redirects = null;

    public function __construct(private readonly ContentModelRegistry $contentModel)
    {
    }

    public function id(): string
    {
        return self::ID;
    }

    public function boot(): void
    {
        self::registerFileKinds();
        add_action('init', [PhpDeclarations::class, 'seal'], self::SEAL_PRIORITY);
        add_filter('proto_blocks_declaration_errors', [$this, 'declarationWarnings'], 10, 4);

        // integration: Track C — create or upgrade the tables:
        //   add_action('plugins_loaded', [new Storage\Installer(), 'ensure']) (or call it now when plugins_loaded already ran).
        // integration: Track C — log retention cron: (new Storage\Retention(...))->schedule().
        // integration: Track B — front end, unless Coexistence::paused($this->settings()):
        //   (new Engine\FrontController($this->registry(), $this->settings(), new Engine\Dispatcher(...)))->register().
        // integration: Track C — permalink monitor when settings monitor.postTypes is non-empty:
        //   (new Monitor($this->registry(), new SchemaFileWriter(SchemaPaths::writableRoots(), AdminPage::fileModsAllowed()), $this->settings()))->register().
        // integration: admin — coexistence notice on the Redirects screen when Coexistence::paused($this->settings()).
    }

    /**
     * The `redirects` kind (`proto-redirects/<slug>/redirects.jsonc`) and the
     * flat `redirect-settings` kind (`proto-redirects/settings.jsonc`) for
     * SchemaFileWriter and the schema-files routes. Called from boot(),
     * before `rest_api_init` builds the route regex; idempotent.
     */
    public static function registerFileKinds(): void
    {
        $errorsOnly = static fn(array $result): array => array_map(
            static fn(array $e): array => ['path' => $e['path'], 'message' => $e['message']],
            $result['errors']
        );
        SchemaFileWriter::extendKind(
            GroupFiles::KIND,
            GroupFiles::FOLDER,
            GroupFiles::BASENAME,
            static fn(array $data): array => $errorsOnly(GroupValidator::validate($data)),
            [
                'slugPattern' => GroupFiles::SLUG_PATTERN,
                'template' => static fn(string $slug, string $name): string => Templates::group($slug, $name),
            ]
        );
        SchemaFileWriter::extendKind(
            GroupFiles::SETTINGS_KIND,
            GroupFiles::FOLDER,
            GroupFiles::SETTINGS_BASENAME,
            static fn(array $data): array => $errorsOnly(SettingsValidator::validate($data)),
            [
                'flat' => true,
                'template' => static fn(string $slug, string $name): string => Templates::settings(),
            ]
        );
    }

    /**
     * `proto_blocks_declaration_errors`: the warnings of a redirects write
     * (unanchored_regex, redirect_chain against the other groups) so the
     * route returns them; errors were already refused by the kind's validator.
     *
     * @param mixed $errors
     * @param mixed $data
     * @return mixed
     */
    public function declarationWarnings($errors, $kind, $data, $slug)
    {
        if (!is_array($errors) || !is_array($data) || !is_string($slug)) {
            return $errors;
        }
        if ($kind === GroupFiles::KIND) {
            $others = array_values(array_filter(
                $this->registry()->enabledRules(),
                static fn(array $rule): bool => $rule['group'] !== $slug
            ));
            $warnings = GroupValidator::validate($data, $others, $slug)['warnings'];
        } elseif ($kind === GroupFiles::SETTINGS_KIND) {
            $warnings = SettingsValidator::validate($data)['warnings'];
        } else {
            return $errors;
        }
        foreach ($warnings as $warning) {
            $errors[] = ['path' => $warning['path'], 'message' => $warning['message'], 'level' => Issue::WARNING, 'code' => $warning['code']];
        }

        return $errors;
    }

    public function registry(): Registry
    {
        return $this->redirects ??= new Registry();
    }

    /** @return array<string, mixed> fully defaulted settings */
    public function settings(): array
    {
        return $this->registry()->settings();
    }

    /** True while the Redirection plugin is active and `coexist` is false. */
    public function paused(): bool
    {
        return Coexistence::paused($this->settings());
    }

    /** @return list<Issue> */
    public function issues(): array
    {
        return $this->registry()->issues();
    }

    /** The content-model registry the module was built with. */
    public function contentModel(): ContentModelRegistry
    {
        return $this->contentModel;
    }

    /** @internal Set by Modules\Manager when the module boots. */
    public static function setInstance(?self $module): void
    {
        self::$instance = $module;
    }

    /** Null when the module is disabled or not booted yet. */
    public static function instance(): ?self
    {
        return self::$instance;
    }
}
