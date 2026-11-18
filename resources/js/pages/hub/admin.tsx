import { Head, router, useForm } from '@inertiajs/react';
import { DataTable, Errors, Panel, Status } from '@/components/hub/shared';
type Member = {
    id: number;
    name: string;
    email: string;
    membership_status: string;
    roles?: (string | { name: string })[];
};
export default function Admin({
    users = [],
    invitations = [],
}: {
    users?: Member[];
    invitations?: Record<string, unknown>[];
}) {
    const form = useForm({ email: '', role: 'member' });
    return (
        <>
            <Head title="Administration" />
            <div className="grid items-start gap-5 xl:grid-cols-[1.5fr_1fr]">
                <Panel title="Members">
                    <div className="overflow-auto">
                        <table className="hub-table">
                            <thead>
                                <tr>
                                    <th>Member</th>
                                    <th>Access</th>
                                    <th>Role</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                {users.map((user) => (
                                    <tr key={user.id}>
                                        <td>
                                            {user.name}
                                            <p className="mt-1 text-muted-foreground">
                                                {user.email}
                                            </p>
                                        </td>
                                        <td>
                                            <Status>
                                                {user.membership_status}
                                            </Status>
                                        </td>
                                        <td>
                                            {user.roles
                                                ?.map((r) =>
                                                    typeof r === 'string'
                                                        ? r
                                                        : r.name,
                                                )
                                                .join(', ') ?? 'member'}
                                        </td>
                                        <td>
                                            <button
                                                className="hub-button"
                                                onClick={() => {
                                                    if (
                                                        window.confirm(
                                                            `Revoke membership for ${user.email}?`,
                                                        )
                                                    )
                                                        router.delete(
                                                            `/admin/members/${user.id}`,
                                                        );
                                                }}
                                            >
                                                Revoke
                                            </button>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </Panel>
                <Panel title="Invite">
                    <form
                        className="space-y-4"
                        onSubmit={(e) => {
                            e.preventDefault();
                            form.post('/admin/invitations', {
                                onSuccess: () => form.reset(),
                            });
                        }}
                    >
                        <label className="block text-xs">
                            Email
                            <input
                                type="email"
                                required
                                className="hub-input mt-2"
                                value={form.data.email}
                                onChange={(e) =>
                                    form.setData('email', e.target.value)
                                }
                            />
                        </label>
                        <label className="block text-xs">
                            Role
                            <select
                                className="hub-input mt-2"
                                value={form.data.role}
                                onChange={(e) =>
                                    form.setData('role', e.target.value)
                                }
                            >
                                <option value="member">Member</option>
                                <option value="researcher">Researcher</option>
                                <option value="owner">Owner</option>
                            </select>
                        </label>
                        <Errors errors={form.errors} />
                        <button
                            className="hub-button primary"
                            disabled={form.processing}
                        >
                            Create invitation
                        </button>
                    </form>
                </Panel>
                <Panel title="Invitations" className="xl:col-span-2">
                    <DataTable rows={invitations} empty="No invitations" />
                </Panel>
            </div>
        </>
    );
}
