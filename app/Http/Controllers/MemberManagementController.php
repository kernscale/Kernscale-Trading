<?php

namespace App\Http\Controllers;

use App\Models\ResearchRun;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

class MemberManagementController extends Controller
{
    public function update(Request $request, User $user): RedirectResponse
    {
        abort_unless($request->user()->hasRole('owner'), 403);
        $data = $request->validate(['role' => 'required|in:member,researcher,owner', 'membership_status' => 'required|in:approved,revoked']);
        $this->change($request, $user, $data['membership_status'], $data['role']);

        return back()->with('status', 'Member updated.');
    }

    public function revoke(Request $request, User $user): RedirectResponse
    {
        abort_unless($request->user()->hasRole('owner'), 403);
        $this->change($request, $user, 'revoked');

        return back()->with('status', 'Membership revoked.');
    }

    private function change(Request $request, User $user, string $status, ?string $role = null): void
    {
        DB::transaction(function () use ($request, $user, $status, $role) {
            Role::where('name', 'owner')->where('guard_name', 'web')->lockForUpdate()->firstOrFail();
            $actor = $request->user()->fresh();
            abort_unless($actor?->membership_status === 'approved' && $actor->hasRole('owner'), 403);
            $target = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $old = ['roles' => $target->getRoleNames()->sort()->values()->all(), 'membership_status' => $target->membership_status];
            $new = ['roles' => $role === null ? $old['roles'] : [$role], 'membership_status' => $status];
            abort_if($target->id === $actor->id && (! in_array('owner', $new['roles'], true) || $status !== 'approved'), 403, 'You cannot revoke or demote yourself.');
            if ($old === $new) {
                return;
            }
            if ($target->membership_status === 'approved' && $target->hasRole('owner') && (! in_array('owner', $new['roles'], true) || $status !== 'approved')) {
                abort_if(User::role('owner')->where('membership_status', 'approved')->count() <= 1, 403, 'Keep an approved owner.');
            }
            if ($role !== null) {
                $target->syncRoles([$role]);
            }
            $target->forceFill(['membership_status' => $status,
                ...($status === 'revoked' ? ['remember_token' => null] : []),
            ])->save();
            if ($status === 'revoked' || ! array_intersect(['owner', 'researcher'], $new['roles'])) {
                ResearchRun::where('user_id', $target->id)->where('status', 'queued')->update(['status' => 'cancelled', 'completed_at' => now(), 'updated_at' => now()]);
                ResearchRun::where('user_id', $target->id)->whereIn('status', ['dispatching', 'waiting', 'waiting_for_input', 'running', 'collecting', 'interrupted'])->update(['status' => 'cancel_requested', 'updated_at' => now()]);
            }
            DB::table('audit_entries')->insert(['user_id' => $actor->id, 'action' => 'member.updated', 'subject_id' => (string) $target->id,
                'metadata' => json_encode(['old' => $old, 'new' => $new], JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now()]);
        });
    }
}
