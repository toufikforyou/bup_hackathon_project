<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\GridWise\Dto\Scenario;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;

final class OptimizeEnergyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'scenario_id' => ['required', 'string', 'max:255'],

            'operator_notes' => ['required', 'array', 'min:1', 'max:3'],
            'operator_notes.*' => ['required', 'string', 'min:1', 'max:2000'],

            'hours' => ['required', 'array', 'size:'.Scenario::HORIZON],
            'hours.*' => ['required', 'array'],
            'hours.*.hour' => ['required', 'integer', 'between:0,23'],
            'hours.*.demand_kwh' => ['required', 'numeric', 'min:0'],
            'hours.*.solar_kwh' => ['required', 'numeric', 'min:0'],
            'hours.*.tariff_bdt_per_kwh' => ['required', 'numeric', 'min:0'],

            'battery' => ['required', 'array'],
            'battery.capacity_kwh' => ['required', 'numeric', 'min:0'],
            'battery.initial_energy_kwh' => ['required', 'numeric', 'min:0'],
            'battery.minimum_energy_kwh' => ['required', 'numeric', 'min:0'],
            'battery.max_charge_kwh_per_hour' => ['required', 'numeric', 'min:0'],
            'battery.max_discharge_kwh_per_hour' => ['required', 'numeric', 'min:0'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $hours = $this->input('hours');

            if (is_array($hours)) {
                $indexes = array_column($hours, 'hour');

                if (count(array_unique($indexes)) !== Scenario::HORIZON) {
                    $validator->errors()->add('hours', 'hours must contain exactly one entry for every hour from 0 to 23.');
                }

                if ($validator->errors()->isNotEmpty()) {
                    return;
                }
            }

            $battery = $this->input('battery');

            if (! is_array($battery)) {
                return;
            }

            $capacity = (float) ($battery['capacity_kwh'] ?? 0);
            $initial = (float) ($battery['initial_energy_kwh'] ?? 0);
            $minimum = (float) ($battery['minimum_energy_kwh'] ?? 0);

            if ($initial > $capacity) {
                $validator->errors()->add('scenario_consistency', 'initial_energy_kwh may not exceed capacity_kwh.');
            }

            if ($minimum > $capacity) {
                $validator->errors()->add('scenario_consistency', 'minimum_energy_kwh may not exceed capacity_kwh.');
            }

            if ($initial < $minimum) {
                $validator->errors()->add('scenario_consistency', 'initial_energy_kwh may not be below minimum_energy_kwh.');
            }
        });
    }

    public function toScenario(): Scenario
    {
        return Scenario::fromArray($this->validated());
    }

    protected function failedValidation(Validator $validator): never
    {
        $errors = $validator->errors()->toArray();
        $structural = array_filter(
            array_keys($errors),
            static fn (string $key): bool => $key !== 'scenario_consistency',
        );

        $semanticOnly = $errors !== [] && $structural === [];

        throw new HttpResponseException(new JsonResponse(
            $semanticOnly
                ? [
                    'error' => 'unprocessable_entity',
                    'message' => 'The request is well formed but describes an impossible scenario.',
                    'details' => $errors,
                ]
                : [
                    'error' => 'bad_request',
                    'message' => 'The request does not match the required scenario schema.',
                    'details' => $errors,
                ],
            $semanticOnly ? JsonResponse::HTTP_UNPROCESSABLE_ENTITY : JsonResponse::HTTP_BAD_REQUEST,
        ));
    }
}
