<?php
declare(strict_types=1);

namespace Modules\VarDumper\Console\Commands;

use App\TCP\Server;
use Modules\VarDumper\Connection as VarDumperConnection;
use Modules\VarDumper\Console\StreamHandlerConfig;
use Closure;
use Illuminate\Console\Command;
use Laravel\Octane\Commands\Concerns\InteractsWithIO;
use Swoole\Coroutine\Server\Connection;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\VarDumper\Command\Descriptor\CliDescriptor;
use Symfony\Component\VarDumper\Dumper\CliDumper;

class StartServerCommand extends Command
{
    use InteractsWithIO;

    protected $signature = 'var-dumper:start
                    {--host= : The IP address the server should bind to}
                    {--port= : The port the server should be available on}';

    protected $description = 'Run var-dumper server';
    private VarDumperConnection $connection;

    public function handle(StreamHandlerConfig $config)
    {
        $this->connection = new VarDumperConnection();

        $server = new Server(
            $this->option('host') ?: '0.0.0.0',
            (int) ($this->option('port') ?: 9912),
            'Var dumper'
        );

        $server
            ->onReceive($this->onReceive($config))
            ->onConnect($this->onConnect())
            ->onClose($this->onClose())
            ->run($this->output);
    }

    public function handleStream($stream, $verbosity = null)
    {
        match ($stream['type']) {
            'request' => !$this->getLaravel()->environment('local', 'testing') ?: $this->requestInfo($stream, $verbosity),
        };
    }

    private function onReceive(StreamHandlerConfig $config): Closure
    {
        $descriptor = new CliDescriptor(new CliDumper());

        return function (Connection $conn, string $data, float $start) use ($config, $descriptor) {

            $this->handleStream([
                'type' => 'request',
                'url' => '',
                'method' => 'VAR-DUMP:DATA',
                'duration' => (microtime(true) - $start) * 1000,
                'statusCode' => 200,
                'memory' => memory_get_usage(),
            ]);

            $this->connection->handleMessage($data, function (array $payload) use ($config, $descriptor, $start) {

                if (!$config->isEnabled()) {
                    return;
                }

                try {
                    [$data, $context] = $payload;

                    unset($context['cli']);

                    $descriptor->describe(
                        new SymfonyStyle($this->input, $this->output->getErrorStyle()),
                        $data,
                        $context,
                        $this->connection->clientId()
                    );

                } catch (\Throwable $e) {
                    $this->error($e->getMessage());
                }


            }, function (string $error) {

                $this->error($error);

            });
        };
    }

    private function onConnect(): Closure
    {
        return function (Connection $conn, float $start) {
            $this->connection->ready($conn);

            $this->handleStream([
                'type' => 'request',
                'url' => $this->connection->clientId(),
                'method' => 'VAR-DUMP:CONNECTED',
                'duration' => (microtime(true) - $start) * 1000,
                'statusCode' => 200,
                'memory' => memory_get_usage(),
            ]);
        };
    }

    private function onClose(): Closure
    {
        return function (Connection $conn, float $start) {
            $this->handleStream([
                'type' => 'request',
                'url' => '',
                'method' => 'VAR-DUMP:CLOSED',
                'duration' => (microtime(true) - $start) * 1000,
                'statusCode' => 200,
                'memory' => memory_get_usage(),
            ]);
        };
    }
}
