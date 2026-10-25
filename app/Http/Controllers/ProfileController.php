<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProfileController extends Controller
{
    /**
     * Return the authenticated user's profile.
     *
     * Only the fields already exposed by the existing /user endpoint are
     * returned. Password and remember-token fields remain hidden by the User
     * model.
     */
    public function show(Request $request): JsonResponse
    {
        return response()->json([
            'user' => $request->user(),
        ]);
    }

    /**
     * Update the authenticated user's display name.
     *
     * Email is intentionally not writable yet: changing a sign-in address
     * safely requires an email-verification flow that this application does
     * not currently have. Security settings (password, 2FA, alerts) likewise
     * remain unsupported rather than pretending to be saved.
     */
    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
        ]);

        $user = $request->user();
        $user->name = trim($validated['name']);
        $user->save();

        return response()->json([
            'user' => $user->fresh(),
            'message' => 'Profile updated.',
        ]);
    }
}
