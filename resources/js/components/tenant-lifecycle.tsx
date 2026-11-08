import { Form, Link } from '@inertiajs/react';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { destroy } from '@/routes/tenant';
import { index as membersIndex } from '@/routes/tenant/members';
import { destroy as leave } from '@/routes/tenant/membership';

export function TenantLifecycle({
    tenantId,
    canLeave,
    canDelete,
    canTransferOwnership,
}: {
    tenantId: string;
    canLeave: boolean;
    canDelete: boolean;
    canTransferOwnership: boolean;
}) {
    return (
        <div className="max-w-xl space-y-8 border-t pt-6">
            {canTransferOwnership && (
                <section className="space-y-4">
                    <Heading
                        variant="small"
                        title="Workspace ownership"
                        description="Transfer ownership to an active member before leaving this workspace or deleting your account. You will become an admin."
                    />
                    <Button variant="outline" asChild>
                        <Link href={membersIndex({ tenant: tenantId })}>
                            Manage ownership in Members
                        </Link>
                    </Button>
                </section>
            )}
            {canLeave && (
                <section className="space-y-4">
                    <Heading
                        variant="small"
                        title="Leave workspace"
                        description="Remove your membership from this workspace. Your account and access to other workspaces will remain."
                    />
                    <Dialog>
                        <DialogTrigger asChild>
                            <Button variant="outline">Leave workspace</Button>
                        </DialogTrigger>
                        <DialogContent>
                            <DialogTitle>Leave this workspace?</DialogTitle>
                            <DialogDescription>
                                You will lose access to this workspace. You will
                                need a new invitation to join again.
                            </DialogDescription>
                            <Form {...leave.form({ tenant: tenantId })}>
                                {({ processing }) => (
                                    <DialogFooter className="gap-2">
                                        <DialogClose asChild>
                                            <Button
                                                type="button"
                                                variant="secondary"
                                                disabled={processing}
                                            >
                                                Cancel
                                            </Button>
                                        </DialogClose>
                                        <Button
                                            type="submit"
                                            variant="destructive"
                                            disabled={processing}
                                        >
                                            Leave workspace
                                        </Button>
                                    </DialogFooter>
                                )}
                            </Form>
                        </DialogContent>
                    </Dialog>
                </section>
            )}
            {canDelete && (
                <section className="space-y-4">
                    <Heading
                        variant="small"
                        title="Delete workspace"
                        description="Permanently delete this workspace, its memberships, and pending invitations. User accounts and other workspaces will remain."
                    />
                    <Dialog>
                        <DialogTrigger asChild>
                            <Button variant="destructive">
                                Delete workspace
                            </Button>
                        </DialogTrigger>
                        <DialogContent>
                            <DialogTitle>Delete this workspace?</DialogTitle>
                            <DialogDescription>
                                This cannot be undone. Enter your password and
                                type DELETE to confirm.
                            </DialogDescription>
                            <Form
                                {...destroy.form({ tenant: tenantId })}
                                options={{ preserveScroll: true }}
                                resetOnSuccess
                                className="space-y-6"
                            >
                                {({
                                    processing,
                                    errors,
                                    resetAndClearErrors,
                                }) => (
                                    <>
                                        <div className="grid gap-2">
                                            <Label htmlFor="delete-workspace-password">
                                                Current password
                                            </Label>
                                            <PasswordInput
                                                id="delete-workspace-password"
                                                name="password"
                                                autoComplete="current-password"
                                                required
                                            />
                                            <InputError
                                                message={errors.password}
                                            />
                                        </div>
                                        <div className="grid gap-2">
                                            <Label htmlFor="delete-workspace-confirmation">
                                                Type DELETE
                                            </Label>
                                            <Input
                                                id="delete-workspace-confirmation"
                                                name="confirmation"
                                                placeholder="DELETE"
                                                autoComplete="off"
                                                required
                                            />
                                            <InputError
                                                message={errors.confirmation}
                                            />
                                        </div>
                                        <DialogFooter className="gap-2">
                                            <DialogClose asChild>
                                                <Button
                                                    type="button"
                                                    variant="secondary"
                                                    onClick={() =>
                                                        resetAndClearErrors()
                                                    }
                                                    disabled={processing}
                                                >
                                                    Cancel
                                                </Button>
                                            </DialogClose>
                                            <Button
                                                type="submit"
                                                variant="destructive"
                                                disabled={processing}
                                            >
                                                Delete workspace
                                            </Button>
                                        </DialogFooter>
                                    </>
                                )}
                            </Form>
                        </DialogContent>
                    </Dialog>
                </section>
            )}
        </div>
    );
}
