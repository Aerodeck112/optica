<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Modules\Finance\Models\FiscalDevice;
use App\Modules\Finance\Services\Fiscal\FiscalReceiptService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** API-ul pentru agentul local de lângă casa de marcat (autentificat prin AuthenticateFiscalAgent). */
class FiscalAgentController extends Controller
{
    public function __construct(private readonly FiscalReceiptService $receipts) {}

    private function device(Request $request): FiscalDevice
    {
        return $request->attributes->get('fiscal_device');
    }

    public function commands(Request $request): JsonResponse
    {
        $device = $this->device($request);
        $device->forceFill(['last_seen_at' => now()])->save();

        return response()->json(['commands' => $this->receipts->claimFor($device)]);
    }

    public function result(Request $request, string $commandId): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', 'in:printed,failed'],
            'fiscal_number' => ['nullable', 'string', 'max:40'],
            'z_report_number' => ['nullable', 'string', 'max:20'],
            'error' => ['nullable', 'string', 'max:1000'],
        ]);
        $receipt = $this->receipts->applyResult($this->device($request), $commandId, $data);

        return $receipt ? response()->json(['ok' => true, 'status' => $receipt->status]) : response()->json(['error' => 'unknown_command'], 404);
    }

    public function heartbeat(Request $request): JsonResponse
    {
        $data = $request->validate(['agent_version' => ['nullable', 'string', 'max:40'], 'device_status' => ['nullable', 'string', 'max:200'], 'paper' => ['nullable', 'string', 'max:40']]);
        $this->device($request)->forceFill(['last_seen_at' => now(), 'last_diagnostic' => $data + ['at' => now()->toIso8601String()], 'status' => 'online'])->save();

        return response()->json(['ok' => true, 'server_time' => now()->toIso8601String()]);
    }
}
