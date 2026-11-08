import { Form } from '@inertiajs/react';
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
import { Label } from '@/components/ui/label';
import { update } from '@/routes/tenant/ownership';

export function TransferTenantOwnershipDialog({
    tenantId,
    member,
}: {
    tenantId: string;
    member: { id: number; name: string };
}) {
    return (
        <Dialog>
            <DialogTrigger asChild>
                <Button
                    variant="outline"
                    size="sm"
                    aria-label={`Transfer ownership to ${member.name}`}
                >
                    Transfer ownership
                </Button>
            </DialogTrigger>
            <DialogContent>
                <DialogTitle>Transfer ownership to {member.name}?</DialogTitle>
                <DialogDescription>
                    They will become the workspace owner and you will become an
                    admin. Enter your password to confirm.
                </DialogDescription>
                <Form
                    {...update.form({ tenant: tenantId, member: member.id })}
                    options={{ preserveScroll: true }}
                    resetOnSuccess
                    className="space-y-6"
                >
                    {({ processing, errors, resetAndClearErrors }) => (
                        <>
                            <div className="grid gap-2">
                                <Label
                                    htmlFor={`transfer-password-${member.id}`}
                                >
                                    Current password
                                </Label>
                                <PasswordInput
                                    id={`transfer-password-${member.id}`}
                                    name="password"
                                    autoComplete="current-password"
                                    required
                                />
                                <InputError message={errors.password} />
                                <InputError message={errors.member} />
                            </div>
                            <DialogFooter className="gap-2">
                                <DialogClose asChild>
                                    <Button
                                        type="button"
                                        variant="secondary"
                                        onClick={() => resetAndClearErrors()}
                                        disabled={processing}
                                    >
                                        Cancel
                                    </Button>
                                </DialogClose>
                                <Button type="submit" disabled={processing}>
                                    Transfer ownership
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}
