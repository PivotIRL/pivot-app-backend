<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UserRequest;
use App\Models\Users;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class UserController extends Controller
{
    public function index(Request $request)
    {
        $query = Users::query();

        if ($request->filled('search')) {
            $search = strtolower(trim($request->input('search')));
            $query->where(function ($q) use ($search) {
                $q->whereRaw('LOWER(name) LIKE ?', ["%{$search}%"])
                    ->orWhereRaw('LOWER(email) LIKE ?', ["%{$search}%"]);
            });
        }

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('provider')) {
            $query->where('provider', $request->input('provider'));
        }

        $users = $query->orderBy('id', 'desc')->paginate(15)->withQueryString();

        $stats = [
            'total'     => Users::count(),
            'ready'     => Users::where('status', 'ready')->count(),
            'not_ready' => Users::where('status', 'not_ready')->count(),
            'google'    => Users::where('provider', 'google')->count(),
            'apple'     => Users::where('provider', 'apple')->count(),
            'email'     => Users::where('provider', 'email')->count(),
        ];

        return view('admin.users.index', compact('users', 'stats'));
    }

    public function store(UserRequest $request)
    {
        $data = $request->validated();
        $data['password'] = Hash::make($data['password']);

        Users::create($data);

        return redirect()->route('admin.users.index')->with('success', 'User created successfully.');
    }

    public function show($id)
    {
        $user = Users::with([
            'userProfile',
            'hobbies',
            'activities',
            'subscriptions.tier',
            'subscriptionLogs',
            'groupChallengeParticipants.session.challenge',
        ])->findOrFail($id);

        $membership = app(\App\Services\Membership\MembershipAccessService::class)->snapshot($user);
        $membershipEnvironment = app(\App\Services\Membership\MembershipEnvironment::class)->forUser($user);

        return view('admin.users.show', compact('user', 'membership', 'membershipEnvironment'));
    }

    public function update(UserRequest $request, $id)
    {
        $user = Users::findOrFail($id);
        $data = $request->validated();

        if (empty($data['password'])) {
            unset($data['password']);
        } else {
            $data['password'] = Hash::make($data['password']);
        }

        $user->update($data);

        return redirect()->route('admin.users.index')->with('success', 'User updated successfully.');
    }

    public function destroy($id)
    {
        $user = Users::findOrFail($id);
        $user->delete();

        return redirect()->route('admin.users.index')->with('success', 'User deleted successfully.');
    }
}
