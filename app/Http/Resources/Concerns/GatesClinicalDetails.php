<?php

namespace App\Http\Resources\Concerns;

use Illuminate\Http\Request;

/**
 * Clinical details (complaint, diagnosis, treatment) of a previous
 * consultation are only shown to users who may view consultations — front
 * desk staff see which visit a follow-up belongs to, not its diagnosis.
 */
trait GatesClinicalDetails
{
    protected function canSeeClinicalDetails(Request $request): bool
    {
        if (! $request->attributes->has('can_see_clinical_details')) {
            $request->attributes->set(
                'can_see_clinical_details',
                (bool) $request->user()?->hasPermission('consultations.view'),
            );
        }

        return $request->attributes->get('can_see_clinical_details');
    }
}
