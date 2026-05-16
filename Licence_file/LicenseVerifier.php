<?php

namespace App\Services;

use Illuminate\Support\Facades\File;

class LicenseVerifier
{
    private ?array $licenseData = null;
    private string $licensePath;
    private string $publicKeyPath;
    private string $currentDomain;
    private ?string $currentIp = null;

    public function __construct()
    {
        $this->licensePath = base_path('license.key');
        $this->publicKeyPath = base_path('public.key');
        $this->currentDomain = request()->getHost() ?? 'localhost';
        $this->currentIp = request()->ip();
    }

    public function verify(): array
    {
        $result = [
            'valid' => false,
            'message' => '',
            'errors' => [],
            'license' => null
        ];

        if (!File::exists($this->licensePath)) {
            $result['message'] = 'License file not found';
            $result['errors'][] = 'license_file_not_found';
            return $result;
        }

        if (!File::exists($this->publicKeyPath)) {
            $result['message'] = 'Public key file not found';
            $result['errors'][] = 'public_key_not_found';
            return $result;
        }

        $licenseContent = File::get($this->licensePath);
        $licenseJson = json_decode($licenseContent, true);

        if (!$licenseJson || !isset($licenseJson['data']) || !isset($licenseJson['signature'])) {
            $result['message'] = 'Invalid license file format';
            $result['errors'][] = 'invalid_format';
            return $result;
        }

        $this->licenseData = $licenseJson['data'];
        $signature = base64_decode($licenseJson['signature']);

        $result['license'] = $this->licenseData;

        if (!$this->verifySignature($signature)) {
            $result['message'] = 'Invalid license signature';
            $result['errors'][] = 'invalid_signature';
            return $result;
        }

        if (!$this->verifyDomain()) {
            $result['message'] = 'License domain mismatch';
            $result['errors'][] = 'domain_mismatch';
            return $result;
        }

        if (!$this->verifyIp()) {
            $result['message'] = 'License server IP mismatch';
            $result['errors'][] = 'ip_mismatch';
            return $result;
        }

        if (!$this->verifyExpiry()) {
            $result['message'] = 'License has expired';
            $result['errors'][] = 'license_expired';
            return $result;
        }

        $result['valid'] = true;
        $result['message'] = 'License is valid';

        return $result;
    }

    private function verifySignature(string $signature): bool
    {
        $publicKey = File::get($this->publicKeyPath);
        $publicKeyResource = openssl_pkey_get_public($publicKey);

        if (!$publicKeyResource) {
            return false;
        }

        $dataToVerify = json_encode($this->licenseData);
        
        return openssl_verify($dataToVerify, $signature, $publicKeyResource, OPENSSL_ALGO_SHA256) === 1;
    }

    private function verifyDomain(): bool
    {
        $licensedDomain = $this->licenseData['domain'] ?? '';
        
        if (empty($licensedDomain)) {
            return true;
        }

        if ($licensedDomain === '*') {
            return true;
        }

        $licensedDomain = strtolower($licensedDomain);
        $currentDomain = strtolower($this->currentDomain);

        if ($licensedDomain === $currentDomain) {
            return true;
        }

        $licensedWithoutWww = str_replace('www.', '', $licensedDomain);
        $currentWithoutWww = str_replace('www.', '', $currentDomain);

        return $licensedWithoutWww === $currentWithoutWww;
    }

    private function verifyIp(): bool
    {
        $licensedIp = $this->licenseData['server_ip'] ?? null;

        if (empty($licensedIp)) {
            return true;
        }

        if ($licensedIp === '*') {
            return true;
        }

        return $licensedIp === $this->currentIp;
    }

    private function verifyExpiry(): bool
    {
        $expiryDate = $this->licenseData['expiry'] ?? null;

        if (empty($expiryDate)) {
            return false;
        }

        $expiry = strtotime($expiryDate);
        $now = time();

        return $expiry > $now;
    }

    public function getLicenseInfo(): ?array
    {
        return $this->licenseData;
    }
}
