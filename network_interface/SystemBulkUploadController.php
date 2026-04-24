<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Facades\LibrenmsConfig;
use App\Models\CustomMib;
use App\Models\AuthLog;
use App\Models\Dashboard;
use App\Models\User;
use App\Models\Device;
use App\Models\UserPref;
use Auth;
use LibreNMS\Config;
use Illuminate\Support\Str;
use LibreNMS\Authentication\LegacyAuth;
use Spatie\Permission\Models\Role;
use Illuminate\Support\Facades\Storage;
use URL;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Session;

class SystemBulkUploadController extends Controller
{
    protected $tftpPath;
    protected $pluginPath;
    protected $venv;

    public function __construct()
    {
        $this->venv = base_path('librenms-ansible-inventory-plugin/bin/activate');
        $this->pluginPath = base_path('librenms-ansible-inventory-plugin');
        $this->tftpPath = '/tftpboot';

        // Create TFTP directory if it doesn't exist
        if (!is_dir($this->tftpPath)) {
            try {
                mkdir($this->tftpPath, 0755, true);
            } catch (\Exception $e) {
                Log::error('Failed to create TFTP directory: ' . $e->getMessage());
            }
        }
    }

    private function runAnsible(string $playbook, string $hosts, array $extraVars = []): string
    {
        $extraVarsString = "";

        if (!empty($extraVars)) {
            foreach ($extraVars as $key => $value) {
                if (is_array($value)) {
                    $value = json_encode($value);
                }
                $extraVarsString .= " --extra-vars '{$key}={$value}'";
            }
        }

        $cmd = "source {$this->venv} && ansible-playbook -i {$hosts} {$playbook}{$extraVarsString} 2>&1";
        return shell_exec($cmd);
    }

     public function addHostIp(Request $request)
    {
        $this->authorize('create', CustomMib::class);
        return view('addhostip.index');
    }

     public function addHostIpsave(Request $request)
    {
        // Validate request
        $request->validate([
            'hostname' => 'required|string',
            'config_file' => 'required|file|mimes:conf,txt,cfg,bin|max:10240', // 10MB max
        ]);

        // Parse IPs from textarea or JSON
        $validIPs = [];
        if ($request->filled('valid_ips')) {
            $validIPs = json_decode($request->valid_ips, true);
        } else {
            // Fallback: Parse from hostname textarea
            $lines = explode("\n", $request->hostname);
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line && !str_starts_with($line, '#') && filter_var($line, FILTER_VALIDATE_IP)) {
                    $validIPs[] = $line;
                }
            }
        }

        if (empty($validIPs)) {
            return response()->json([
                'success' => false,
                'message' => 'No valid IP addresses found'
            ], 400);
        }

        // -----------------------------
    // STEP 2: Store + Read Config File
    // -----------------------------
    $configFile = $request->file('config_file');
    $filename = time() . '_' . $configFile->getClientOriginalName();

    $storedPath = $configFile->storeAs('temp/configs', $filename);
    $fullPath = storage_path('app/' . $storedPath);

    if (!file_exists($fullPath)) {
        return response()->json([
            'success' => false,
            'message' => 'Config file upload failed'
        ], 500);
        }

        // Read file line by line
        $fileContent = file($fullPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        $commands = [];
        foreach ($fileContent as $line) {
            $line = trim($line);
           //|| str_starts_with($line, '!')
            // Skip comments
            if ($line === '' || str_starts_with($line, '#') ) {
                continue;
            }

            $commands[] = $line;
        }

        if (empty($commands)) {
            return response()->json([
                'success' => false,
                'message' => 'No valid commands found in config file'
            ], 400);
        }
        //dd($commands); // Debug commands`   

        // -----------------------------
        // STEP 3: Credentials
        // -----------------------------
        $ansibleUser = $request->input('ansible_user', 'admin');
        $ansiblePassword = $request->input('ansible_password', 'admin');
        $snmpCommunity = $request->input('snmp_community', 'public');

        // -----------------------------
        // STEP 4: Inventory Path
        // -----------------------------
        $basePath = "/opt/librenms";
        $inventoryDir = $basePath . "/librenms-ansible-inventory-plugin/hosts/";

        if (!file_exists($inventoryDir)) {
            mkdir($inventoryDir, 0755, true);
        }

        // -----------------------------
        // STEP 5: Process Each IP
        // -----------------------------
        $results = [];
        $successCount = 0;
        $failedCount = 0;

        foreach ($validIPs as $ip) {
            $hostnameip=trim($ip);
            $hostname = 'bridge_' . str_replace('.', '_', trim($ip));
            $inventoryFile = $inventoryDir . $hostname . ".yml";

            // Generate inventory
            $inventoryContent = $this->generateInventoryYaml(
                $hostname,
                trim($ip),
                $ansibleUser,
                $ansiblePassword,
                $snmpCommunity
            );

            try {
                file_put_contents($inventoryFile, $inventoryContent);
            } catch (\Exception $e) {
                $results[] = [
                    'ip' => $ip,
                    'status' => 'failed',
                    'error' => 'Inventory creation failed'
                ];
                $failedCount++;
                continue;
            }

            // -----------------------------
            // STEP 6: Run Ansible
            // -----------------------------
            $playbook = $basePath . "/librenms-ansible-inventory-plugin/playbooks/firstconfiguploadip.yml";

            if (!file_exists($playbook)) {
                return response()->json([
                    'success' => false,
                    'message' => "Playbook not found"
                ], 500);
            }
            //dd($commands);

            $extraVars = [
                'hostname' => $hostnameip,
                'cli_commands' => $commands,
                'ansible_user' => $ansibleUser,
                'ansible_password' => $ansiblePassword
            ];

            try {
                
                $output = $this->runAnsible($playbook, $inventoryFile, $extraVars);
                dd($output); // Debug output
                // Add to LibreNMS
                $librenmsResult = $this->addDeviceToLibreNMS(trim($ip), $snmpCommunity);

                $results[] = [
                    'ip' => $ip,
                    'hostname' => $hostname,
                    'status' => 'success',
                    'ansible_output' => $output,
                    'librenms' => $librenmsResult
                ];

                $successCount++;

            } catch (\Exception $e) {

                $results[] = [
                    'ip' => $ip,
                    'hostname' => $hostname,
                    'status' => 'failed',
                    'error' => $e->getMessage()
                ];

                $failedCount++;
            }
        }

        // -----------------------------
        // FINAL RESPONSE
        // -----------------------------
        return response()->json([
            'success' => true,
            'message' => "Success: {$successCount}, Failed: {$failedCount}",
            'results' => $results,
            'summary' => [
                'total' => count($validIPs),
                'success' => $successCount,
                'failed' => $failedCount
            ]
        ]);
}
    /**
     * Generate inventory YAML content
     */
    private function generateInventoryYaml($hostname, $ip, $username, $password, $community)
    {
        return <<<YAML
    all:
      children:
        alphabridge_devices:
          hosts:
            bridge1:
              ansible_host: {$ip}
              ansible_user: {$username}
              ansible_password: {$password}
              ansible_connection: local
              ansible_python_interpreter: /usr/bin/python3
              os: switchv1alphabridge
              snmpver: v2c
              community: {$community}
    
    YAML;
    }


    public function index(Request $request)
    {
        $this->authorize('viewAny', CustomMib::class);

        // Get dropdown list (unique hardware)
        $deviceFilter = Device::whereNotNull('hardware')
            ->where('hardware', '!=', '')
            ->distinct()
            ->orderBy('hardware')
            ->pluck('hardware');

        // Get selected model_names (multiple selection)
        $selectedModels = $request->input('model_names', []);

        if (!is_array($selectedModels)) {
            $selectedModels = [$selectedModels];
        }

        // Filter out empty values
        $selectedModels = array_filter($selectedModels);

        // Remove duplicate selections
        $selectedModels = array_unique($selectedModels);

        // Devices query
        $devicesQuery = Device::orderBy('hostname')
            ->select('device_id', 'hostname', 'sysName', 'sysObjectID', 'hardware', 'status');

        // Apply filters if selected
        if (!empty($selectedModels)) {
            $devicesQuery->whereIn('hardware', $selectedModels);
        }

        $devices = $devicesQuery->get();

        // Get previously uploaded files from session (if any)
        $uploadedFiles = Session::get('system_bulk_uploads', []);

        return view('syssoftbulk.index', compact(
            'devices',
            'deviceFilter',
            'selectedModels',
            'uploadedFiles'
        ));
    }


   

    public function process(Request $request)
    {
        $this->authorize('create', CustomMib::class);

        $request->validate([
            'selected_devices' => 'required|array',
            'selected_devices.*' => 'exists:devices,device_id',
            'uploads' => 'required|array',
            'uploads.*' => 'required|file|mimes:bin|max:102400',
        ]);

        $devices = Device::whereIn('device_id', $request->selected_devices)->get();
        $uploads = $request->file('uploads');

        if (!is_dir($this->tftpPath) || !is_writable($this->tftpPath)) {
            return back()->with('error', 'TFTP directory not writable');
        }

        $modelBaseFiles = [];
        $success = 0;
        $failed = [];

        /*
         |--------------------------------------------------------------------------
         | STEP 1: Save Firmware Once Per Model
         |--------------------------------------------------------------------------
         */
        foreach ($uploads as $model => $file) {

            $safeModel = preg_replace('/[^a-zA-Z0-9\-_]/', '', $model);
            $extension = $file->getClientOriginalExtension();

            $baseName = "firmware_{$safeModel}.{$extension}";
            $basePath = $this->tftpPath . '/' . $baseName;

            if (file_exists($basePath)) {
                unlink($basePath);
            }

            $file->move($this->tftpPath, $baseName);
            chmod($basePath, 0644);

            $modelBaseFiles[$model] = $basePath;
        }

        /*
         |--------------------------------------------------------------------------
         | STEP 2: Copy Firmware Per Device (Ansible Friendly)
         |--------------------------------------------------------------------------
         */
        foreach ($devices as $device) {

            $model = $device->hardware;

            if (!isset($modelBaseFiles[$model])) {
                $failed[] = $device->hostname . ' (No firmware for model)';
                continue;
            }

            try {
                $safeHostname = preg_replace('/[^a-zA-Z0-9\-_]/', '', $device->hostname);
                $extension = pathinfo($modelBaseFiles[$model], PATHINFO_EXTENSION);

                $deviceFile = "{$safeHostname}.{$extension}";
                $devicePath = $this->tftpPath . '/' . $deviceFile;

                if (file_exists($devicePath)) {
                    unlink($devicePath);
                }

                copy($modelBaseFiles[$model], $devicePath);
                chmod($devicePath, 0644);

                /*
                 |--------------------------------------------------------------------------
                 | OPTIONAL: Trigger Ansible Here
                 |--------------------------------------------------------------------------
                 */

                $this->runAnsibleFirmwareUpload($device, $deviceFile);

                $success++;

            } catch (\Exception $e) {
                $failed[] = $device->hostname . ' (' . $e->getMessage() . ')';
            }
        }

        if ($success > 0) {
            return redirect()->route('system.bulk.upload')
                ->with('status', "$success device(s) firmware prepared & Ansible triggered.");
        }

        return back()->with('error', 'All uploads failed: ' . implode(', ', $failed));
    }


    private function runAnsibleFirmwareUpload($device, $filename)
    {
       
        $hosts = "{$this->pluginPath}/hosts/{$device->hostname}.yml";
        $playbook = "{$this->pluginPath}/playbooks/tftpupload.yml";


        $tftpServer = "192.168.200.128"; 
        $destination_file="switch.bin";    

        $extraVars = [
            'tftp_server' => $tftpServer,
            'filename' => $filename,
            'destination_file' => $destination_file,
        ];

        $output = $this->runAnsible($playbook, $hosts, $extraVars);

        Log::info("Ansible output for {$device->hostname}: " . $output);

    }



    /**
     * Clear uploaded files session
     */
    public function clearSession()
    {
        Session::forget('system_bulk_uploads');
        Session::save();

        return redirect()->route('system.bulk.upload')
            ->with('info', 'Upload session has been cleared');
    }

    /**
     * Get list of uploaded files
     */
    public function getUploadedFiles()
    {
        $uploadedFiles = Session::get('system_bulk_uploads', []);

        return response()->json([
            'success' => true,
            'files' => $uploadedFiles
        ]);
    }
}