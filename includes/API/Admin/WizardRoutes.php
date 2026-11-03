<?php
/**
 * WizardRoutes - REST routes for the Setup Wizard screen
 *
 * @package ProtoBlocks
 */

declare(strict_types=1);

namespace ProtoBlocks\API\Admin;

use ProtoBlocks\Admin\SetupWizard;
use ProtoBlocks\API\AdminRestController;
use WP_Error;
use WP_REST_Request;

/**
 * GET admin/wizard, POST admin/wizard/style, /demos and /complete. Only the
 * demo install writes files, so only it takes the write permission.
 */
final class WizardRoutes
{
    private ?SetupWizard $wizard;

    public function __construct(?SetupWizard $wizard = null)
    {
        $this->wizard = $wizard;
    }

    public function registerRoutes(string $namespace, callable $permission, callable $writePermission): void
    {
        $base = '/' . AdminRestController::BASE . '/wizard';

        register_rest_route($namespace, $base, [
            [
                'methods' => 'GET',
                'callback' => [$this, 'get'],
                'permission_callback' => $permission,
            ],
        ]);

        register_rest_route($namespace, $base . '/style', [
            [
                'methods' => 'POST',
                'callback' => [$this, 'saveStyle'],
                'permission_callback' => $permission,
                'args' => [
                    'style' => [
                        'type' => 'string',
                        'required' => true,
                    ],
                    'categoryName' => [
                        'type' => 'string',
                        'required' => false,
                        'default' => '',
                        'validate_callback' => [SystemRoutes::class, 'validateCategoryName'],
                    ],
                ],
            ],
        ]);

        register_rest_route($namespace, $base . '/demos', [
            [
                'methods' => 'POST',
                'callback' => [$this, 'installDemos'],
                'permission_callback' => $writePermission,
            ],
        ]);

        register_rest_route($namespace, $base . '/complete', [
            [
                'methods' => 'POST',
                'callback' => [$this, 'complete'],
                'permission_callback' => $permission,
            ],
        ]);
    }

    /** @return array<string,mixed> */
    public function get(): array
    {
        return $this->wizard()->data();
    }

    /** @return array<string,mixed> */
    public function saveStyle(WP_REST_Request $request): array
    {
        $this->wizard()->saveStyle(
            (string) $request->get_param('style'),
            (string) ($request->get_param('categoryName') ?? '')
        );

        return $this->wizard()->data();
    }

    /** @return array{installed:int,skipped:string[]}|WP_Error */
    public function installDemos()
    {
        $result = $this->wizard()->installDemos();
        if (!$result instanceof WP_Error) {
            return $result;
        }

        $code = (string) $result->get_error_code();
        $status = match ($code) {
            'forbidden', 'file_mods_disallowed' => 403,
            'invalid_demo_type' => 400,
            default => 500,
        };

        return new WP_Error(
            'proto_blocks_' . sanitize_key($code),
            $result->get_error_message(),
            ['status' => $status]
        );
    }

    /** @return array{redirect:string} */
    public function complete(): array
    {
        $this->wizard()->complete();

        return ['redirect' => admin_url('admin.php?page=proto-blocks')];
    }

    private function wizard(): SetupWizard
    {
        return $this->wizard ??= new SetupWizard();
    }
}
