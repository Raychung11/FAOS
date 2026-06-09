<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Logger;

/**
 * LHDN MyInvois (IRBM) e-invoice API client.
 *
 * Targets the official MyInvois API (preprod / prod base URL configurable),
 * OAuth2 client-credentials. When credentials are not configured it degrades
 * gracefully: the compliant document is still generated and stored locally
 * with status "pending_submission" — flip it on by setting the .env keys.
 */
final class MyInvoisClient
{
    public static function enabled(): bool
    {
        return Config::get('MYINVOIS_CLIENT_ID', '') !== ''
            && Config::get('MYINVOIS_CLIENT_SECRET', '') !== '';
    }

    private static function baseUrl(): string
    {
        $env = strtolower((string) Config::get('MYINVOIS_ENV', 'preprod'));
        if (Config::get('MYINVOIS_BASE_URL')) {
            return rtrim((string) Config::get('MYINVOIS_BASE_URL'), '/');
        }
        return $env === 'prod'
            ? 'https://api.myinvois.hasil.gov.my'
            : 'https://preprod-api.myinvois.hasil.gov.my';
    }

    private static function token(): ?string
    {
        try {
            $ch = curl_init(self::baseUrl() . '/connect/token');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
                CURLOPT_POSTFIELDS => http_build_query([
                    'grant_type' => 'client_credentials',
                    'client_id' => Config::get('MYINVOIS_CLIENT_ID'),
                    'client_secret' => Config::get('MYINVOIS_CLIENT_SECRET'),
                    'scope' => 'InvoicingAPI',
                ]),
                CURLOPT_TIMEOUT => 30,
            ]);
            $out = curl_exec($ch);
            curl_close($ch);
            $j = json_decode((string) $out, true);
            return $j['access_token'] ?? null;
        } catch (\Throwable $e) {
            Logger::error('MyInvois token failed', ['msg' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Submit one document. Returns
     *   ['ok'=>bool,'uuid'=>?,'long_id'=>?,'status'=>?,'raw'=>array]
     * When not configured returns ok=false with status 'pending_submission'
     * (caller keeps the locally-generated document).
     */
    public static function submit(array $document): array
    {
        if (!self::enabled()) {
            return ['ok' => false, 'status' => 'pending_submission', 'raw' => ['note' => 'MyInvois not configured']];
        }
        $token = self::token();
        if (!$token) {
            return ['ok' => false, 'status' => 'pending_submission', 'raw' => ['note' => 'auth failed']];
        }
        try {
            $json = json_encode($document);
            $payload = [
                'documents' => [[
                    'format' => 'JSON',
                    'document' => base64_encode($json),
                    'documentHash' => hash('sha256', $json),
                    'codeNumber' => $document['Invoice'][0]['ID'][0]['_'] ?? uniqid('FAOS'),
                ]],
            ];
            $ch = curl_init(self::baseUrl() . '/api/v1.0/documentsubmissions');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_HTTPHEADER => [
                    'Authorization: Bearer ' . $token,
                    'Content-Type: application/json',
                ],
                CURLOPT_POSTFIELDS => json_encode($payload),
                CURLOPT_TIMEOUT => 45,
            ]);
            $out = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            $resp = json_decode((string) $out, true) ?: [];
            $accepted = $resp['acceptedDocuments'][0] ?? null;
            if ($code < 300 && $accepted) {
                return [
                    'ok' => true,
                    'uuid' => $accepted['uuid'] ?? null,
                    'long_id' => $accepted['longId'] ?? null,
                    'status' => 'valid',
                    'raw' => $resp,
                ];
            }
            return ['ok' => false, 'status' => 'rejected', 'raw' => $resp];
        } catch (\Throwable $e) {
            Logger::error('MyInvois submit failed', ['msg' => $e->getMessage()]);
            return ['ok' => false, 'status' => 'pending_submission', 'raw' => ['error' => $e->getMessage()]];
        }
    }

    /** Public validation URL for the IRBM-issued document. */
    public static function validationUrl(?string $uuid, ?string $longId): ?string
    {
        if (!$uuid || !$longId) {
            return null;
        }
        $portal = strtolower((string) Config::get('MYINVOIS_ENV', 'preprod')) === 'prod'
            ? 'https://myinvois.hasil.gov.my'
            : 'https://preprod.myinvois.hasil.gov.my';
        return "{$portal}/{$uuid}/share/{$longId}";
    }
}
