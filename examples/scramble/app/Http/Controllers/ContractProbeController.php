<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\StoreUserRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\Request;

final class ContractProbeController
{
    public function store(StoreUserRequest $request): UserResource
    {
        $user = User::create([
            ...$request->validated(),
            'password' => 'unused-local-probe',
        ]);

        /** @status 201 */
        return new UserResource($user);
    }

    public function show(User $user): UserResource
    {
        return new UserResource($user);
    }

    public function profile(Request $request): UserResource
    {
        return new UserResource($request->user());
    }
}
