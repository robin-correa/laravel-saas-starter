import { Form, Head, Link } from '@inertiajs/react';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { dashboard } from '@/routes';
import { create, store } from '@/routes/workspaces';

export default function CreateWorkspace() {
    return (
        <>
            <Head title="Create workspace" />
            <div className="space-y-6 p-4 md:p-6">
                <h1 className="sr-only">Create workspace</h1>
                <Heading
                    title="Create workspace"
                    description="Create a workspace and become its owner."
                />
                <Form
                    {...store.form()}
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
                                    autoComplete="organization"
                                    placeholder="Acme Inc."
                                    maxLength={255}
                                    required
                                    autoFocus
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
                            <div className="flex items-center gap-3">
                                <Button
                                    type="submit"
                                    disabled={processing}
                                    data-test="create-workspace-button"
                                >
                                    {processing && <Spinner />}
                                    Create workspace
                                </Button>
                                <Button type="button" variant="outline" asChild>
                                    <Link href={dashboard()}>Cancel</Link>
                                </Button>
                            </div>
                        </>
                    )}
                </Form>
            </div>
        </>
    );
}

CreateWorkspace.layout = {
    breadcrumbs: [{ title: 'Create workspace', href: create() }],
};
