<?php

namespace Tests\Unit\Console\Commands;

use Tests\TestCase;
use App\Models\Tractor;
use App\Models\User;
use App\Models\Farm;
use App\Models\GpsMetricsCalculation;
use App\Models\Warning;
use App\Console\Commands\CheckTractorStoppageWarnings;
use App\Services\TractorStoppageWarningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Mockery;
use Mockery\MockInterface;
use Illuminate\Console\OutputStyle;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

class CheckTractorStoppageWarningsTest extends TestCase
{
    use RefreshDatabase;

    private CheckTractorStoppageWarnings $command;
    private TractorStoppageWarningService|MockInterface $warningService;
    private Farm $farm;
    private User $user;
    private Tractor $tractor;
    private GpsMetricsCalculation $dailyReport;
    private Warning $warning;
    private BufferedOutput $output;

    protected function setUp(): void
    {
        parent::setUp();

        $this->warningService = Mockery::mock(TractorStoppageWarningService::class);
        $this->output = new BufferedOutput();
        $this->command = new CheckTractorStoppageWarnings($this->warningService);
        $this->command->setOutput(new OutputStyle(new ArrayInput([]), $this->output));

        $this->farm = Farm::factory()->create();
        $this->user = User::factory()->create();
        $this->tractor = Tractor::factory()->create(['farm_id' => $this->farm->id]);
        $this->dailyReport = GpsMetricsCalculation::factory()->create([
            'tractor_id' => $this->tractor->id,
            'date' => today(),
            'stoppage_duration' => 7200 // 2 hours in seconds
        ]);

        // Set up the farm-user relationship
        $this->farm->users()->attach($this->user->id);

        // Set user's working environment
        $this->user->preferences = ['working_environment' => $this->farm->id];
        $this->user->save();

        // Create warning configuration
        $this->warning = Warning::create([
            'farm_id' => $this->farm->id,
            'key' => 'tractor_stoppage',
            'enabled' => true,
            'parameters' => ['hours' => 1],
            'type' => 'condition-based'
        ]);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    #[Test]
    public function it_executes_successfully_when_no_errors_occur()
    {
        $this->warningService
            ->shouldReceive('checkAndNotify')
            ->once()
            ->andReturn(null);

        $this->assertEquals(0, $this->command->handle());
    }

    #[Test]
    public function it_handles_errors_gracefully()
    {
        $this->warningService
            ->shouldReceive('checkAndNotify')
            ->once()
            ->andThrow(new \Exception('Test error'));

        $this->assertEquals(1, $this->command->handle());
    }

    #[Test]
    public function it_outputs_success_message()
    {
        $this->warningService
            ->shouldReceive('checkAndNotify')
            ->once()
            ->andReturn(null);

        $this->command->handle();

        $this->assertEquals(
            'Checking for tractor stoppage warnings...' . PHP_EOL .
            'Tractor stoppage warning check completed successfully.' . PHP_EOL,
            $this->output->fetch()
        );
    }

    #[Test]
    public function it_outputs_error_message_when_exception_occurs()
    {
        $this->warningService
            ->shouldReceive('checkAndNotify')
            ->once()
            ->andThrow(new \Exception('Test error'));

        $this->command->handle();

        $this->assertEquals(
            'Checking for tractor stoppage warnings...' . PHP_EOL .
            'Error checking tractor stoppage warnings: Test error' . PHP_EOL,
            $this->output->fetch()
        );
    }
}
