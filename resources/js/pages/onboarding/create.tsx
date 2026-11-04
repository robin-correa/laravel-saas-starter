import { Form, Head } from '@inertiajs/react';
import InputError from '@/components/input-error';
import TextLink from '@/components/text-link';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { logout } from '@/routes';
import { store } from '@/routes/onboarding';

export default function CreateWorkspace() {
    return (
        <>
            <Head title="Create your workspace" />

            <Form
                {...store.form()}
                disableWhileProcessing
                className="flex flex-col gap-6"
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
                                type="text"
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

                        <Button
                            type="submit"
                            disabled={processing}
                            className="w-full"
                            data-test="create-workspace-button"
                        >
                            {processing && <Spinner />}
                            Create workspace
                        </Button>
                    </>
                )}
            </Form>

            <TextLink href={logout()} as="button" className="mx-auto text-sm">
                Log out
            </TextLink>
        </>
    );
}

CreateWorkspace.layout = {
    title: 'Create your workspace',
    description: 'Give your workspace a name to get started.',
};
