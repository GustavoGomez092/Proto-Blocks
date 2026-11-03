<?php
/**
 * PB E2E Fields: the `event` side group with a code field, in PHP. The other
 * groups live in proto-fields/.
 *
 * @package PbE2eFields
 */

proto_field_group('event', [
    'key' => 'group_pb_e2e_developer',
    'title' => 'Developer',
    'position' => 'side',
    'fields' => [
        ['key' => 'field_pb_e2e_snippet', 'label' => 'Snippet', 'name' => 'snippet', 'type' => 'code'],
    ],
]);
