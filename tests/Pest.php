<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
| Only Feature tests get a database. Unit is reserved for logic that needs none —
| enums, data objects, ordering arithmetic, cycle detection — so it stays fast. A test
| that touches Eloquent, a factory or a migration belongs in Feature, whatever it is
| testing.
*/
