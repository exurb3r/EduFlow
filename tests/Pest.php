<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Notification;
use Mockery\CompositeExpectation;
use Mockery\MockInterface;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', fn () => $this->toBe(1));

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

require_once __DIR__.'/Helpers/ArcSubmission.php';
require_once __DIR__.'/Helpers/PaymentAuthorization.php';
require_once __DIR__.'/Helpers/TuitionConsent.php';
require_once __DIR__.'/Helpers/StudentBillFixtures.php';

if (! function_exists('mockExpectation')) {
    /**
     * Narrow a Mockery expectation to the concrete class.
     *
     * `Mockery::mock()` reports `shouldReceive()` as `ExpectationInterface|
     * HigherOrderMessage`, neither of which declares the fluent setters the
     * tests actually use (`andReturnUsing()`, `andThrow()`, ...). Mockery
     * always returns a `Mockery\CompositeExpectation` in practice, so this
     * states it once instead of pretending the interface is richer than it is.
     */
    function mockExpectation(MockInterface $mock, string $method): CompositeExpectation
    {
        $expectation = $mock->shouldReceive($method);

        if (! $expectation instanceof CompositeExpectation) {
            throw new RuntimeException('Mockery returned an expectation that cannot be configured further.');
        }

        return $expectation;
    }
}

if (! function_exists('makeDatabaseNotification')) {
    /**
     * Minimal database-channel notification so tests can seed real rows without
     * committing a notification class to app/.
     *
     * @param  array<string, mixed>  $data
     */
    function makeDatabaseNotification(array $data): Notification
    {
        return new class($data) extends Notification
        {
            /**
             * @param  array<string, mixed>  $data
             */
            public function __construct(private readonly array $data) {}

            /**
             * @return list<string>
             */
            public function via(object $notifiable): array
            {
                return ['database'];
            }

            /**
             * @return array<string, mixed>
             */
            public function toArray(object $notifiable): array
            {
                return $this->data;
            }
        };
    }
}
