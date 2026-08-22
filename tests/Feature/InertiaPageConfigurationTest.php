<?php

it('discovers Inertia pages using the project directory case', function () {
    $pagePath = resource_path('js/pages');

    expect(config('inertia.testing.ensure_pages_exist'))->toBeTrue()
        ->and(config('inertia.testing.page_paths'))->toBe([$pagePath])
        ->and(is_dir($pagePath))->toBeTrue()
        ->and(is_file($pagePath.'/Dashboard/Index.vue'))->toBeTrue()
        ->and(is_file($pagePath.'/Financial/AccountsPayable/Create.vue'))->toBeTrue();
});
