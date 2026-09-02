<?php

namespace Tests\Unit\Http\Requests;

use App\Http\Requests\FilterIrrigationReportsRequest;
use Tests\TestCase;

class FilterIrrigationReportsRequestTest extends TestCase
{
    public function test_explicit_null_scope_arrays_are_normalized_without_counting_null(): void
    {
        $request = FilterIrrigationReportsRequest::create('/api/farms/14/irrigations/filter-reports', 'POST', [
            'field_ids' => null,
            'plot_ids' => null,
            'valve_ids' => null,
            'valves' => null,
            'from_date' => '2026-08-10',
            'to_date' => '2026-08-10',
        ]);

        $prepare = new \ReflectionMethod($request, 'prepareForValidation');
        $prepare->setAccessible(true);
        $prepare->invoke($request);

        $this->assertSame([], $request->input('field_ids'));
        $this->assertSame([], $request->input('plot_ids'));
        $this->assertSame([], $request->input('valve_ids'));
        $this->assertSame([], $request->input('valves'));

        // No farm route is supplied in this unit test, so the farm ownership
        // queries are skipped. The callback must still be safe for [] scopes.
        $validator = new class {
            public function after(callable $callback): void
            {
                $callback($this);
            }

            public function errors(): object
            {
                return new class {
                    public function add(string $key, string $message): void {}
                };
            }
        };

        $withValidator = new \ReflectionMethod($request, 'withValidator');
        $withValidator->setAccessible(true);
        $withValidator->invoke($request, $validator);

        $this->assertTrue(true);
    }
}
