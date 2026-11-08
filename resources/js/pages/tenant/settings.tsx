import { Form, Head, setLayoutProps, usePage } from '@inertiajs/react';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { TenantLifecycle } from '@/components/tenant-lifecycle';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { dashboard } from '@/routes/tenant';
import { edit, update } from '@/routes/tenant/settings';

export default function WorkspaceSettings({
    canUpdate,
    canLeave,
    canDelete,
    canTransferOwnership,
}: {
    canUpdate: boolean;
    canLeave: boolean;
    canDelete: boolean;
    canTransferOwnership: boolean;
}) {
    const { tenancy } = usePage().props;
    const tenant = tenancy.current!;

    setLayoutProps({
        breadcrumbs: [
            {
                title: tenant.name || 'Untitled workspace',
                href: dashboard({ tenant: tenant.id }),
            },
            { title: 'Workspace settings', href: edit({ tenant: tenant.id }) },
        ],
    });

    return (
        <>
            <Head title="Workspace settings" />
            <div className="space-y-6 p-4 md:p-6">
                <h1 className="sr-only">Workspace settings</h1>
                <Heading
                    title="Workspace settings"
                    description="Manage the name of this workspace."
                />
                <Form
                    key={`${tenant.id}-${tenant.name}`}
                    {...update.form({ tenant: tenant.id })}
                    options={{ preserveScroll: true }}
                    disableWhileProcessing
                    className="max-w-xl space-y-6"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor="workspace-name">
                                    Workspace name
                                </Label>
                                <Input
                                    id="workspace-name"
                                    name="name"
                                    defaultValue={tenant.name || ''}
                                    autoComplete="organization"
                                    maxLength={255}
                                    required
                                    disabled={!canUpdate}
                                    readOnly={!canUpdate}
                                    aria-invalid={Boolean(errors.name)}
                                    aria-describedby={
                                        errors.name
                                            ? 'workspace-name-error'
                                            : undefined
                                    }
                                />
                                <InputError
                                    id="workspace-name-error"
                                    message={errors.name}
                                />
                            </div>
                            {canUpdate ? (
                                <Button
                                    type="submit"
                                    disabled={processing}
                                    data-test="update-workspace-button"
                                >
                                    Save
                                </Button>
                            ) : (
                                <p className="text-sm text-muted-foreground">
                                    You don’t have permission to update this
                                    workspace.
                                </p>
                            )}
                        </>
                    )}
                </Form>
                <TenantLifecycle
                    tenantId={tenant.id}
                    canLeave={canLeave}
                    canDelete={canDelete}
                    canTransferOwnership={canTransferOwnership}
                />
            </div>
        </>
    );
}
