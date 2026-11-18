<?php

foreach (array_slice($argv, 1) as $p) {
    $s = file_get_contents($p);
    if ($s === false || $s === '') {
        echo "bad $p\n";

        continue;
    }
    if (strpos($s, 'WithoutSeeding') !== false) {
        echo "skip $p\n";

        continue;
    }
    if (strpos($s, "\nuses(RefreshDatabase::class);") === false || strpos($s, "use Illuminate\Foundation\Testing\RefreshDatabase;\n") === false) {
        echo "nomatch $p\n";

        continue;
    }
    $s = str_replace("\nuses(RefreshDatabase::class);", "\nuses(WithoutSeeding::class, RefreshDatabase::class);", $s);
    $s = str_replace("use Illuminate\Foundation\Testing\RefreshDatabase;\n", "use Illuminate\Foundation\Testing\RefreshDatabase;\nuse Tests\Support\WithoutSeeding;\n", $s);
    file_put_contents($p, $s);
    echo "ok $p\n";
}
