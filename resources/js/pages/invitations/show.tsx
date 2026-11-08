import { Form, Head, router } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { logout } from '@/routes';
import { accept, show } from '@/routes/invitations';

type Invitation = {
    workspace_name: string | null;
    email: string;
    role: string;
    expired: boolean;
    email_matches: boolean;
};

export default function ShowInvitation({
    invitation,
    token,
}: {
    invitation: Invitation;
    token: string;
}) {
    return (
        <>
            <Head title="Workspace invitation" />
            <div className="space-y-6">
                <p className="text-sm text-muted-foreground">
                    You have been invited to join{' '}
                    <strong className="text-foreground">
                        {invitation.workspace_name || 'Untitled workspace'}
                    </strong>{' '}
                    with the {invitation.role} role.
                </p>

                {invitation.expired ? (
                    <p role="status" className="text-sm text-muted-foreground">
                        This invitation has expired. Ask the workspace
                        administrator to resend it.
                    </p>
                ) : invitation.email_matches ? (
                    <Form {...accept.form({ token })} disableWhileProcessing>
                        {({ processing }) => (
                            <Button
                                type="submit"
                                className="w-full"
                                disabled={processing}
                            >
                                Accept invitation
                            </Button>
                        )}
                    </Form>
                ) : (
                    <div className="space-y-4">
                        <p
                            role="status"
                            className="text-sm text-muted-foreground"
                        >
                            This invitation is for {invitation.email}. Sign in
                            with that email address to accept it.
                        </p>
                        <Form
                            {...logout.form()}
                            onSuccess={() => router.visit(show({ token }))}
                        >
                            {({ processing }) => (
                                <Button
                                    type="submit"
                                    variant="outline"
                                    className="w-full"
                                    disabled={processing}
                                >
                                    Sign in with another account
                                </Button>
                            )}
                        </Form>
                    </div>
                )}
            </div>
        </>
    );
}

ShowInvitation.layout = {
    title: 'Workspace invitation',
    description: 'Review your invitation to join a workspace.',
};
