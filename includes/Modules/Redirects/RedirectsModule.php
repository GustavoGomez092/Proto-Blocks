<?php

declare(strict_types=1);

namespace ProtoBlocks\Modules\Redirects;

use ProtoBlocks\Admin\AdminPage;
use ProtoBlocks\ContentModel\Issue;
use ProtoBlocks\ContentModel\Registry as ContentModelRegistry;
use ProtoBlocks\ContentModel\SchemaFileWriter;
use ProtoBlocks\ContentModel\SchemaPaths;
use ProtoBlocks\Modules\ModuleInterface;
use ProtoBlocks\Modules\Redirects\Definition\GroupValidator;
use ProtoBlocks\Modules\Redirects\Definition\SettingsValidator;
use ProtoBlocks\Modules\Redirects\Engine\Conditions\Ip;
use ProtoBlocks\Modules\Redirects\Engine\Dispatcher;
use ProtoBlocks\Modules\Redirects\Engine\FrontController;
use ProtoBlocks\Modules\Redirects\Engine\Request;
use ProtoBlocks\Modules\Redirects\Sources\GroupFiles;
use ProtoBlocks\Modules\Redirects\Sources\PhpDeclarations;
use ProtoBlocks\Modules\Redirects\Storage\Hits;
use ProtoBlocks\Modules\Redirects\Storage\Installer;
use ProtoBlocks\Modules\Redirects\Storage\IpResolver;
use ProtoBlocks\Modules\Redirects\Storage\RedirectLog;
use ProtoBlocks\Modules\Redirects\Storage\Retention;

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

    /** @var (callable(string, string): void)|null tests only: replaces header() */
    private static $headerHandler = null;
    /** @var (callable(): void)|null tests only: replaces exit */
    private static $exitHandler = null;

    private ?Registry $redirects = null;
    private ?FrontController $front = null;
    private ?Monitor $monitor = null;
    private ?Retention $retention = null;
    private string $phpFingerprint = '';

    public function __construct(private readonly ContentModelRegistry $contentModel)
    {
    }

    public function id(): string
    {
        return self::ID;
    }

    /**
     * Wires the module (runs on `after_setup_theme` 1 through Modules\Manager):
     * file kinds and PHP sealing; tables on `plugins_loaded` (or now when it
     * already ran); the log-retention cron; the client-IP resolver of the
     * `ip` condition; the front end unless Coexistence pauses it; the
     * permalink monitor when `monitor.postTypes` is non-empty.
     *
     * Settings are read here (one registry build per request, shared with the
     * engine). PHP declarations made between now and the `init` 9 seal make
     * the registry rebuild once at the seal; the engine reads its rules
     * lazily on the first match, so it always sees the sealed set.
     */
    public function boot(): void
    {
        self::registerFileKinds();
        add_action('init', [PhpDeclarations::class, 'seal'], self::SEAL_PRIORITY);
        add_action('init', [$this, 'onSealed'], self::SEAL_PRIORITY);
        add_filter('proto_blocks_declaration_errors', [$this, 'declarationWarnings'], 10, 4);

        $installer = new Installer();
        if (did_action('plugins_loaded') > 0) {
            $installer->ensure();
        } else {
            add_action('plugins_loaded', [$installer, 'ensure']);
        }

        $settings = $this->settings();
        $this->phpFingerprint = self::phpFingerprint();

        $this->retention = new Retention($settings);
        $this->retention->register();
        $this->retention->schedule();

        $log = is_array($settings['log'] ?? null) ? $settings['log'] : [];
        Ip::setResolver(static fn(Request $request): ?string => IpResolver::resolve($request->server, $log));

        if (!Coexistence::paused($settings)) {
            $dispatcher = new Dispatcher(
                $settings,
                self::$headerHandler ?? static function (string $name, string $value): void {
                    header($name . ': ' . $value, true);
                },
                self::$exitHandler ?? static function (): never {
                    exit;
                },
                new Hits($settings),
                new RedirectLog($settings)
            );
            $this->front = new FrontController(fn(): array => $this->registry()->enabledRules(), $settings, $dispatcher);
            $this->front->register();
        }

        $postTypes = $settings['monitor']['postTypes'] ?? [];
        if (is_array($postTypes) && $postTypes !== []) {
            $this->monitor = new Monitor($this->registry(), new SchemaFileWriter(SchemaPaths::writableRoots(), AdminPage::fileModsAllowed()), $settings);
            $this->monitor->register();
        }
    }

    /**
     * `init` 9, after PhpDeclarations::seal(): rebuild the registry once when
     * proto_redirect()/proto_redirect_group() ran after boot() read it.
     */
    public function onSealed(): void
    {
        if ($this->redirects !== null && self::phpFingerprint() !== $this->phpFingerprint) {
            $this->redirects->invalidate();
        }
        $this->phpFingerprint = self::phpFingerprint();
    }

    /** Plugin activation: create the tables now. */
    public static function activate(): void
    {
        (new Installer())->ensure();
    }

    /** Plugin deactivation: stop the retention cron; data and tables stay (spec §5). */
    public static function deactivate(): void
    {
        (new Retention())->unschedule();
    }

    /** Null while paused by Coexistence or before boot(). */
    public function frontController(): ?FrontController
    {
        return $this->front;
    }

    /** Null unless `monitor.postTypes` is non-empty. */
    public function monitor(): ?Monitor
    {
        return $this->monitor;
    }

    /**
     * @internal Tests only: replace header() and exit in the production Dispatcher
     * built by the next boot(); null restores them.
     */
    public static function setResponseHandlers(?callable $header, ?callable $exit): void
    {
        self::$headerHandler = $header;
        self::$exitHandler = $exit;
    }

    private static function phpFingerprint(): string
    {
        return md5((string) json_encode(
            [PhpDeclarations::rules(), PhpDeclarations::groups(), count(PhpDeclarations::issues())],
            JSON_PARTIAL_OUTPUT_ON_ERROR
        ));
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

    /**
     * Registry findings (discovery, validation, precedence, chains) and the
     * permalink monitor's kept notices. Match-time problems (regex limits,
     * refused targets) are per request and go to the redirect log instead.
     *
     * @return list<Issue>
     */
    public function issues(): array
    {
        return [...$this->registry()->issues(), ...($this->monitor?->issues() ?? [])];
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
