<?php

namespace App\Console\Commands;

use App\Models\VPS\Server;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class RebuildDockerContainer extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'container:rebuild {ip}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Rebuild docker container';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return void
     */
    public function handle()
    {
        $ip = $this->argument('ip');

        $server = Server::where('status' , '<>', Server::STATUS_DISABLE)
            ->where('ip', $ip)
			->first();
        if (!$server) {
            echo 'The server disabled or not exist.'.PHP_EOL;
            return;
        }

        $username = 'root';
        $password = $server->ssh_pwd;
        $ip = $server->ip;
        $port = $server->ssh_port;

        try {
            echo "Connecting $ip ...".PHP_EOL;
            $connection = ssh2_connect($ip, $port);
            ssh2_auth_password($connection, $username, $password);
            echo "Connect success".PHP_EOL;

            echo "Get Docker Status...".PHP_EOL;
            $stream = ssh2_exec($connection, 'docker ps -a');
            stream_set_blocking($stream, true);
            $docker_ps_output = stream_get_contents($stream);
            $docker_ps = explode("\n", $docker_ps_output);
            foreach ($docker_ps as $key => $value) {
                if ($key >= 1 && !empty($value)) {
                    $ps = array_values(array_filter(explode(' ', $value)));
                    $dockerName = end($ps);
                    $indexString = substr($dockerName, 6);

                    $config = file(storage_path("v2ray/account/$ip/config-$indexString.txt"), FILE_IGNORE_NEW_LINES);
                    dd($config);

                    echo "indexString: $indexString".PHP_EOL;

                    if (in_array('Up', $ps)) {
                        $active = true;
                        echo 'Docker 啟用狀態'.PHP_EOL;
                    } else {
                        $active = false;
                        echo 'Docker 停用狀態'.PHP_EOL;
                    }

                    ssh2_exec($connection, 'docker rm -f '.$dockerName);
                    ssh2_exec($connection, "docker run -d --name=v2ray-$indexString -v /etc/v2ray:/etc/v2ray \
                    -p $port:$port --memory=80M --restart=always v2ray/official  \
                    v2ray -config=/etc/v2ray/config-$indexString.json");
                }
            }

            ssh2_exec($connection, 'exit');
            unset($connection);

        } catch (\Exception $exception) {
            echo $exception->getMessage().PHP_EOL;
            Log::error('Rebuild docker container. error: '.$exception->getMessage());
        }
    }
}
