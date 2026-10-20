<?php

namespace App\Http\Requests;

class CorrectSavingsGoalContributionRequest extends StoreSavingsGoalContributionRequest
{
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'note' => 'required|string|max:500',
        ]);
    }
}
