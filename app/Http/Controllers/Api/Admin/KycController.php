<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\KycLimit;
use App\Models\KycSubmission;
use App\Notifications\KycReviewedNotification;
use App\Services\KycService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

/**
 * Revue des demandes KYC et configuration des plafonds par niveau.
 */
class KycController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return response()->json(
            KycSubmission::with('user:id,name,phone,email,kyc_level')
                ->when($request->query('status'), fn ($q, $v) => $q->where('status', $v))
                ->oldest('id')
                ->paginate(30)
        );
    }

    public function show(string $id): JsonResponse
    {
        $submission = KycSubmission::with(['user:id,name,phone,email,kyc_level', 'reviewer:id,name'])->findOrFail($id);

        return response()->json([
            'status' => 'success',
            'data' => $submission->toArray() + [
                // Rôle + index : le fichier se télécharge via files/{index}, sans exposer le chemin.
                'files' => collect($submission->files)->map(fn ($f, $i) => ['index' => $i, 'role' => $f['role'], 'mime' => $f['mime'], 'size' => $f['size']])->all(),
            ],
        ]);
    }

    public function file(string $id, int $index)
    {
        $file = KycSubmission::findOrFail($id)->files[$index] ?? abort(404);
        $disk = Storage::disk($file['disk']);

        abort_unless($disk->exists($file['path']), 404);

        return $disk->download($file['path'], "kyc-{$id}-{$file['role']}.".pathinfo($file['path'], PATHINFO_EXTENSION));
    }

    public function approve(Request $request, string $id, KycService $kyc): JsonResponse
    {
        return $this->review(fn ($s) => $kyc->approve($s, $request->user()), $id);
    }

    public function reject(Request $request, string $id, KycService $kyc): JsonResponse
    {
        $data = $request->validate(['reason' => 'required|string|min:3|max:500']);

        return $this->review(fn ($s) => $kyc->reject($s, $request->user(), $data['reason']), $id);
    }

    private function review(callable $action, string $id): JsonResponse
    {
        $submission = KycSubmission::with('user')->findOrFail($id);

        try {
            $reviewed = $action($submission);
        } catch (InvalidArgumentException $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 409);
        }

        $submission->user->notify(new KycReviewedNotification($reviewed));

        return response()->json(['status' => 'success', 'data' => $reviewed]);
    }

    public function limits(): JsonResponse
    {
        return response()->json(['status' => 'success', 'data' => KycLimit::orderBy('level')->get()]);
    }

    /** Plafonds en devise du wallet ; null = illimité. Cohérence : ils ne doivent pas diminuer d'un niveau à l'autre. */
    public function updateLimits(Request $request): JsonResponse
    {
        $data = $request->validate([
            'limits' => 'required|array|size:'.count(config('kyc.levels')),
            'limits.*.level' => 'required|integer|in:'.implode(',', config('kyc.levels')).'|distinct',
            'limits.*.per_transaction' => 'nullable|numeric|min:0',
            'limits.*.daily_limit' => 'nullable|numeric|min:0',
            'limits.*.monthly_limit' => 'nullable|numeric|min:0',
        ]);

        $rows = collect($data['limits'])->sortBy('level')->values();

        foreach (['per_transaction', 'daily_limit', 'monthly_limit'] as $field) {
            $previous = 0.0;
            foreach ($rows as $row) {
                $value = $row[$field] ?? null;
                // Illimité (null) n'est valide qu'à partir d'un niveau supérieur à tous les plafonnés.
                if ($value !== null && (float) $value < $previous) {
                    return response()->json(['status' => 'error', 'message' => "Le plafond « {$field} » ne peut pas baisser quand le niveau monte."], 422);
                }
                if ($value === null) {
                    $previous = INF;
                } else {
                    $previous = (float) $value;
                }
            }
        }

        foreach ($rows as $row) {
            KycLimit::updateOrCreate(['level' => $row['level']], [
                'per_transaction' => $row['per_transaction'] ?? null,
                'daily_limit' => $row['daily_limit'] ?? null,
                'monthly_limit' => $row['monthly_limit'] ?? null,
            ]);
        }

        return $this->limits();
    }
}
