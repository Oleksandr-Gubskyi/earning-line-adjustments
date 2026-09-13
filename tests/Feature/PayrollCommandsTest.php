<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Payroll\Application\Command\CalculateEarningLine;
use Payroll\Application\Handler\CalculateEarningLineHandler;
use Payroll\Domain\EarningLine\EarningLineId;
use Payroll\Domain\Money;
use Tests\TestCase;

final class PayrollCommandsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * One expectation per test on purpose: Laravel consumes expected output
     * substrings progressively, so chaining several in one call is unreliable.
     */
    public function test_the_demo_verifies_itself_against_the_expected_value(): void
    {
        $this->artisan('payroll:demo')
            ->expectsOutputToContain('matches the assignment')
            ->assertExitCode(0);
    }

    public function test_the_demo_reports_the_expected_final_value(): void
    {
        $this->artisan('payroll:demo')
            ->expectsOutputToContain('$1,104.45')
            ->assertExitCode(0);
    }

    public function test_the_demo_renders_the_audit_history(): void
    {
        // Rendered by payroll:show, which the demo calls -- so the read side is
        // exercised through its own entry point rather than inlined here.
        $this->artisan('payroll:demo')
            ->expectsOutputToContain('Audit history')
            ->assertExitCode(0);
    }

    public function test_the_demo_shows_the_ignored_recalculation(): void
    {
        // Step 4 of the assignment is the whole point of the freeze rule, so the
        // walkthrough has to make it visible even though it records no event.
        $this->artisan('payroll:demo')
            ->expectsOutputToContain('IGNORED')
            ->assertExitCode(0);
    }

    public function test_show_renders_the_history_of_an_existing_line(): void
    {
        $id = EarningLineId::generate();

        $this->app->make(CalculateEarningLineHandler::class)(
            new CalculateEarningLine($id, Money::fromDecimalString('1000.00')),
        );

        $this->artisan('payroll:show', ['lineId' => $id->toString()])
            ->expectsOutputToContain('$1,000.00')
            ->assertExitCode(0);
    }

    public function test_show_reports_an_unknown_line_without_a_stack_trace(): void
    {
        $this->artisan('payroll:show', ['lineId' => '00000000-0000-4000-8000-000000000000'])
            ->expectsOutputToContain('No earning line exists')
            ->assertExitCode(1);
    }

    public function test_show_rejects_an_id_that_is_not_a_uuid(): void
    {
        $this->artisan('payroll:show', ['lineId' => 'not-a-uuid'])
            ->expectsOutputToContain('must be a UUID')
            ->assertExitCode(1);
    }
}
