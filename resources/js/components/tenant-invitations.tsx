import { Form, Link } from '@inertiajs/react';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { destroy, resend, store } from '@/routes/tenant/invitations';
import { index } from '@/routes/tenant/members';

type Invitation = {
    id: number;
    email: string;
    role: string;
    expires_at: string;
    expired: boolean;
    can_manage: boolean;
};

export type InvitationsPage = {
    data: Invitation[];
    current_page: number;
    last_page: number;
};

export function TenantInvitations({
    tenantId,
    inviteRoles,
    invitations,
}: {
    tenantId: string;
    inviteRoles: string[];
    invitations: InvitationsPage;
}) {
    return (
        <section
            className="space-y-6 rounded-lg border p-4 md:p-6"
            aria-label="Workspace invitations"
        >
            <Heading
                variant="small"
                title="Invite members"
                description="Send an email invitation to join this workspace. Invitations expire after seven days."
            />
            <Form
                {...store.form({ tenant: tenantId })}
                options={{ preserveScroll: true }}
                resetOnSuccess={['email']}
                className="space-y-3"
            >
                {({ processing, errors }) => (
                    <>
                        <div className="flex flex-col gap-3 sm:flex-row sm:items-end">
                            <div className="grid flex-1 gap-2">
                                <Label htmlFor="invitation-email">
                                    Email address
                                </Label>
                                <Input
                                    id="invitation-email"
                                    name="email"
                                    type="email"
                                    autoComplete="email"
                                    maxLength={255}
                                    required
                                    placeholder="colleague@example.com"
                                    aria-invalid={Boolean(errors.email)}
                                    aria-describedby={
                                        errors.email
                                            ? 'invitation-email-error'
                                            : undefined
                                    }
                                />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="invitation-role">Role</Label>
                                <Select
                                    name="role"
                                    defaultValue="member"
                                    disabled={processing}
                                >
                                    <SelectTrigger
                                        id="invitation-role"
                                        className="w-full sm:w-36"
                                    >
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {inviteRoles.map((role) => (
                                            <SelectItem key={role} value={role}>
                                                <span className="capitalize">
                                                    {role}
                                                </span>
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                            <Button type="submit" disabled={processing}>
                                Send invitation
                            </Button>
                        </div>
                        <InputError
                            id="invitation-email-error"
                            message={errors.email}
                        />
                        <InputError message={errors.role} />
                    </>
                )}
            </Form>

            <div className="space-y-3">
                <h3 className="text-sm font-medium">Pending invitations</h3>
                {invitations.data.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        No pending invitations.
                    </p>
                ) : (
                    <ul className="divide-y">
                        {invitations.data.map((invitation) => (
                            <li
                                key={invitation.id}
                                className="flex flex-wrap items-center justify-between gap-3 py-3"
                            >
                                <div className="min-w-0 space-y-1">
                                    <p className="text-sm font-medium break-all">
                                        {invitation.email}
                                    </p>
                                    <div className="flex flex-wrap items-center gap-2 text-xs text-muted-foreground">
                                        <Badge
                                            variant="secondary"
                                            className="capitalize"
                                        >
                                            {invitation.role}
                                        </Badge>
                                        {invitation.expired ? (
                                            <span>Expired</span>
                                        ) : (
                                            <span>
                                                Expires{' '}
                                                <time
                                                    dateTime={
                                                        invitation.expires_at
                                                    }
                                                >
                                                    {new Date(
                                                        invitation.expires_at,
                                                    ).toLocaleDateString()}
                                                </time>
                                            </span>
                                        )}
                                    </div>
                                </div>
                                {invitation.can_manage && (
                                    <div className="flex flex-wrap gap-2">
                                        <Form
                                            {...resend.form({
                                                tenant: tenantId,
                                                invitation: invitation.id,
                                            })}
                                            options={{ preserveScroll: true }}
                                        >
                                            {({ processing, errors }) => (
                                                <div className="space-y-2">
                                                    <Button
                                                        type="submit"
                                                        variant="outline"
                                                        size="sm"
                                                        disabled={processing}
                                                        aria-label={`Resend invitation to ${invitation.email}`}
                                                    >
                                                        Resend
                                                    </Button>
                                                    <InputError
                                                        message={errors.email}
                                                    />
                                                </div>
                                            )}
                                        </Form>
                                        <Form
                                            {...destroy.form({
                                                tenant: tenantId,
                                                invitation: invitation.id,
                                            })}
                                            options={{ preserveScroll: true }}
                                        >
                                            {({ processing }) => (
                                                <Button
                                                    type="submit"
                                                    variant="outline"
                                                    size="sm"
                                                    disabled={processing}
                                                    aria-label={`Cancel invitation to ${invitation.email}`}
                                                >
                                                    Cancel
                                                </Button>
                                            )}
                                        </Form>
                                    </div>
                                )}
                            </li>
                        ))}
                    </ul>
                )}
            </div>

            {invitations.last_page > 1 && (
                <nav
                    aria-label="Invitations pagination"
                    className="flex items-center justify-end gap-3"
                >
                    {invitations.current_page > 1 && (
                        <Button variant="outline" size="sm" asChild>
                            <Link
                                href={index(
                                    { tenant: tenantId },
                                    {
                                        mergeQuery: {
                                            invitations_page:
                                                invitations.current_page - 1,
                                        },
                                    },
                                )}
                                preserveScroll
                            >
                                Previous
                            </Link>
                        </Button>
                    )}
                    <span className="text-sm">
                        Page {invitations.current_page} of{' '}
                        {invitations.last_page}
                    </span>
                    {invitations.current_page < invitations.last_page && (
                        <Button variant="outline" size="sm" asChild>
                            <Link
                                href={index(
                                    { tenant: tenantId },
                                    {
                                        mergeQuery: {
                                            invitations_page:
                                                invitations.current_page + 1,
                                        },
                                    },
                                )}
                                preserveScroll
                            >
                                Next
                            </Link>
                        </Button>
                    )}
                </nav>
            )}
        </section>
    );
}
