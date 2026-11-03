<?php

declare(strict_types=1);

// A WP_User whose magic __isset/__get fall through to user meta, as core's do.
// Loaded only inside a separate process (FieldReaderTest), so no other test
// ever sees a WP_User class.

if (!class_exists('WP_User')) {
    class WP_User
    {
        public int $ID = 0;

        /** @var array<string, mixed> user meta the magic accessors fall through to */
        public array $meta = [];

        public function __construct(int $id, array $meta = [])
        {
            $this->ID = $id;
            $this->meta = $meta;
        }

        public function __isset($key)
        {
            return isset($this->meta[$key]);
        }

        public function __get($key)
        {
            return $this->meta[$key] ?? null;
        }
    }
}
