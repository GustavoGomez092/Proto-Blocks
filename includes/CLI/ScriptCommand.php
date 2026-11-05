<?php
/**
 * WP-CLI: wp proto-blocks script list|create|validate|enable|disable|delete.
 *
 * @package ProtoBlocks
 */

declare(strict_types=1);

namespace ProtoBlocks\CLI;

use ProtoBlocks\API\AdminRestController;
use ProtoBlocks\ContentModel\Issue;
use ProtoBlocks\ContentModel\SchemaFileWriter;
use ProtoBlocks\ContentModel\SchemaPaths;
use ProtoBlocks\ContentModel\WriteException;
use ProtoBlocks\Modules\Scripts\Definition\ScriptNormalizer;
use ProtoBlocks\Modules\Scripts\Definition\Targets;
use ProtoBlocks\Modules\Scripts\JsoncEdit;
use ProtoBlocks\Modules\Scripts\Output;
use ProtoBlocks\Modules\Scripts\Registry;
use ProtoBlocks\Modules\Scripts\ScriptsModule;
use ProtoBlocks\Modules\Scripts\ScriptTemplate;
use ProtoBlocks\Modules\Scripts\SnippetFiles;
use ProtoBlocks\Modules\Scripts\Sources\PhpDeclarations;
use WP_CLI;
use WP_CLI_Command;

/**
 * Manage head / body-open / body-close scripts declared in
 * proto-scripts/<slug>/script.jsonc or with proto_script().
 *
 * Writes go to the active theme (or its parent) under the same rules as the
 * admin screen, except the unfiltered_html check: the shell user already
 * owns the theme files.
 */
final class ScriptCommand extends WP_CLI_Command
{
    private const COLUMNS = ['slug', 'title', 'enabled', 'priority', 'locations', 'where', 'origin'];
    private const ISSUE_COLUMNS = ['level', 'code', 'slug', 'source', 'message'];

    public function __construct(
        private ?Registry $scripts = null,
        private ?SchemaFileWriter $writer = null,
        private ?SnippetFiles $snippets = null
    ) {
    }

    /**
     * List declared scripts.
     *
     * ## OPTIONS
     *
     * [--format=<format>]
     * : table, json or csv. Default table.
     *
     * @param list<string>         $args
     * @param array<string, mixed> $assoc
     */
    public function list(array $args, array $assoc): void
    {
        WP_CLI\Utils\format_items((string) ($assoc['format'] ?? 'table'), $this->rows(), self::COLUMNS);
    }

    /**
     * Create proto-scripts/<slug>/script.jsonc from the commented template.
     *
     * ## OPTIONS
     *
     * <title>
     * : The script title.
     *
     * [--slug=<slug>]
     * : Defaults to the slugified title.
     *
     * [--priority=<priority>]
     * : Default 10; lower runs first.
     *
     * @param list<string>         $args
     * @param array<string, mixed> $assoc
     */
    public function create(array $args, array $assoc): void
    {
        $title = trim((string) ($args[0] ?? ''));
        if ($title === '') {
            WP_CLI::error('Usage: wp proto-blocks script create <title> [--slug=<slug>] [--priority=<n>]');
            return;
        }
        $slug = isset($assoc['slug']) ? trim((string) $assoc['slug']) : PhpDeclarations::slugFromTitle($title);
        if ($slug === '' || preg_match(SchemaFileWriter::EXTENDED_SLUG_PATTERN, $slug) !== 1) {
            WP_CLI::error(sprintf('"%s" is not a valid script slug: start with a lowercase letter and use only a-z, 0-9, "_" and "-" (32 characters at most). Pass --slug.', Commands::safeArg($slug)));
            return;
        }
        $priority = ScriptNormalizer::DEFAULT_PRIORITY;
        if (isset($assoc['priority'])) {
            $raw = (string) $assoc['priority'];
            if (preg_match('/^-?\d{1,6}$/D', $raw) !== 1 || abs((int) $raw) > 100000) {
                WP_CLI::error('--priority must be an integer between -100000 and 100000.');
                return;
            }
            $priority = (int) $raw;
        }

        $writer = $this->writer();
        if ($writer->existing(ScriptsModule::KIND, $slug) !== null) {
            WP_CLI::error(sprintf('proto-scripts/%s/script.jsonc already exists.', $slug));
            return;
        }
        try {
            $written = $writer->write(ScriptsModule::KIND, $slug, ScriptTemplate::render($title, $priority), null, null);
        } catch (WriteException $e) {
            WP_CLI::error($e->getMessage());
            return;
        }
        $this->scripts()->invalidate();
        WP_CLI::success('Created ' . self::relative($written['path']));
    }

    /**
     * Check every script file and proto_script() declaration. Exits 1 on errors.
     *
     * ## OPTIONS
     *
     * [--format=<format>]
     * : table, json or csv. Default table.
     *
     * @param list<string>         $args
     * @param array<string, mixed> $assoc
     */
    public function validate(array $args, array $assoc): void
    {
        $scripts = $this->scripts();
        $issues = [...$scripts->issues(), ...Output::bodyOpenIssues($scripts->all())];
        $rows = array_map(static fn (Issue $i): array => [
            'level' => $i->level,
            'code' => $i->code,
            'slug' => (string) $i->slug,
            'source' => self::relative($i->source),
            'message' => $i->message,
        ], $issues);
        $errors = count(array_filter($issues, static fn (Issue $i): bool => $i->level === Issue::ERROR));

        if ($rows !== []) {
            WP_CLI\Utils\format_items((string) ($assoc['format'] ?? 'table'), $rows, self::ISSUE_COLUMNS);
        }
        if ($errors > 0) {
            WP_CLI::error(sprintf('%d error(s) in script declarations.', $errors));
            return;
        }
        WP_CLI::success(sprintf('%d script(s) valid%s.', count($scripts->all()), $rows === [] ? '' : sprintf(', %d warning(s)', count($rows))));
    }

    /**
     * Turn a script file on.
     *
     * ## OPTIONS
     *
     * <slug>
     * : The script slug.
     *
     * @param list<string>         $args
     * @param array<string, mixed> $assoc
     */
    public function enable(array $args, array $assoc): void
    {
        $this->setEnabled((string) ($args[0] ?? ''), true);
    }

    /**
     * Turn a script file off (it stays in the theme).
     *
     * ## OPTIONS
     *
     * <slug>
     * : The script slug.
     *
     * @param list<string>         $args
     * @param array<string, mixed> $assoc
     */
    public function disable(array $args, array $assoc): void
    {
        $this->setEnabled((string) ($args[0] ?? ''), false);
    }

    /**
     * Delete a script file, and its folder when it holds nothing but the script and its snippet files.
     *
     * ## OPTIONS
     *
     * <slug>
     * : The script slug.
     *
     * --confirm=<slug>
     * : The slug again.
     *
     * @param list<string>         $args
     * @param array<string, mixed> $assoc
     */
    public function delete(array $args, array $assoc): void
    {
        $slug = (string) ($args[0] ?? '');
        if (($assoc['confirm'] ?? null) !== $slug || $slug === '') {
            WP_CLI::error('Pass --confirm=<slug> with the same slug to delete a script.');
            return;
        }
        $existing = $this->fileFor($slug);
        if ($existing === null) {
            return;
        }
        $dir = dirname($existing->source);
        $whole = SnippetFiles::deletable($dir);
        try {
            $result = $this->writer()->delete(ScriptsModule::KIND, $slug, $existing);
            $kept = $whole ? $this->snippets()->removeLeftovers($dir) : $result['kept'];
        } catch (WriteException $e) {
            WP_CLI::error($e->getMessage());
            return;
        }
        $this->scripts()->invalidate();
        if ($kept !== []) {
            WP_CLI::warning(sprintf('Kept in %s: %s', self::relative($dir), implode(', ', $kept)));
        }
        WP_CLI::success('Deleted ' . self::relative($result['deleted']));
    }

    /**
     * @return list<array{slug: string, title: string, enabled: string, priority: int, locations: string, where: string, origin: string}>
     */
    public function rows(): array
    {
        $rows = [];
        foreach ($this->scripts()->all() as $script) {
            $rows[] = [
                'slug' => (string) $script['slug'],
                'title' => (string) $script['title'],
                'enabled' => $script['enabled'] ? 'yes' : 'no',
                'priority' => (int) $script['priority'],
                'locations' => implode(',', ScriptNormalizer::present($script)),
                'where' => Targets::summary($script['where']),
                'origin' => $script['double'] !== null ? 'file (also php)' : (string) $script['origin'],
            ];
        }

        return $rows;
    }

    private function setEnabled(string $slug, bool $enabled): void
    {
        $existing = $this->fileFor($slug);
        if ($existing === null) {
            return;
        }
        $text = (string) file_get_contents($existing->source);
        try {
            $next = JsoncEdit::setTopLevel($text, 'enabled', $enabled);
            $this->writer()->write(ScriptsModule::KIND, $slug, $next, SchemaFileWriter::hash($text), $existing);
        } catch (WriteException | \InvalidArgumentException $e) {
            WP_CLI::error($e->getMessage());
            return;
        }
        $this->scripts()->invalidate();
        WP_CLI::success(sprintf('%s %s.', $enabled ? 'Enabled' : 'Disabled', $slug));
    }

    /** The script's file under the writable roots, or an error (and null). */
    private function fileFor(string $slug): ?\ProtoBlocks\ContentModel\Declaration
    {
        if ($slug === '' || preg_match(SchemaFileWriter::EXTENDED_SLUG_PATTERN, $slug) !== 1) {
            WP_CLI::error(sprintf('"%s" is not a valid script slug.', Commands::safeArg($slug)));
            return null;
        }
        $existing = $this->writer()->existing(ScriptsModule::KIND, $slug);
        if ($existing === null) {
            $php = in_array($slug, PhpDeclarations::slugs(), true);
            WP_CLI::error($php
                ? sprintf('The script "%s" is declared in PHP (%s); change it in the theme code.', $slug, self::relative((string) PhpDeclarations::source($slug)))
                : sprintf('There is no proto-scripts/%s/script.jsonc in the theme.', $slug));
            return null;
        }

        return $existing;
    }

    private static function relative(string $path): string
    {
        $at = strrpos($path, '/proto-scripts/');

        return $at === false ? $path : substr($path, $at + 1);
    }

    private function scripts(): Registry
    {
        return $this->scripts ??= ScriptsModule::instance()?->scripts() ?? new Registry();
    }

    private function writer(): SchemaFileWriter
    {
        // Registered here too: files can be managed while the module is off.
        ScriptsModule::registerFileKind();

        return $this->writer ??= new SchemaFileWriter(SchemaPaths::writableRoots(), AdminRestController::fileWritesAllowed());
    }

    private function snippets(): SnippetFiles
    {
        return $this->snippets ??= new SnippetFiles(SchemaPaths::writableRoots(), AdminRestController::fileWritesAllowed());
    }
}
