<?php

namespace App\Modules\Payments\Http\Resources;

use App\Modules\Doctors\Models\Doctor;
use App\Modules\Payments\Models\DoctorFee;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A doctor and their current fee, or null for one with no fee (R7). The resource is the
 * ['doctor' => Doctor, 'fee' => ?DoctorFee] pair from FeeService.
 */
class DoctorFeeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Doctor $doctor */
        $doctor = $this->resource['doctor'];
        /** @var DoctorFee|null $fee */
        $fee = $this->resource['fee'];

        return [
            'doctor' => [
                'id' => $doctor->id,
                'name' => $doctor->user->name,
                'title' => $doctor->title,
            ],
            'amount' => $fee?->amount,
            'set_at' => $fee?->updated_at->toISOString(),
            'set_by' => $fee === null ? null : ['id' => $fee->setBy->id, 'name' => $fee->setBy->name],
        ];
    }
}
