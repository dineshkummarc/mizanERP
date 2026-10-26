<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * What a client learns about the account it just signed in to — docs/desktop-mobile-plan.md, A1.
 *
 * A deliberate shape rather than the user model: the model carries columns nobody outside should see and
 * grows new ones without anybody thinking of this response. The companies are here because they are the
 * next thing a client needs — ResolveCompanyFromUser wants an `X-Company` header from an account that
 * belongs to more than one.
 */
class AuthenticatedUserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'companies' => $this->companies()
                ->get(['companies.id', 'companies.name', 'companies.slug'])
                ->map(fn ($company): array => [
                    'id' => $company->id,
                    'name' => $company->name,
                    'slug' => $company->slug,
                ])
                ->values(),
        ];
    }
}
