# Tests

Run with WP-CLI from a WordPress install where the plugin is active:

    wp eval-file /path/to/repo/tests/run.php [filter] [verbose]

Pure tests (no WordPress):

    php tests/unit.php [filter] [verbose]

`filter` matches a substring of `Dir/Class::method`.
