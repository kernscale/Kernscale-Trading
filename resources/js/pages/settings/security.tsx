import { Form, Head, Link } from '@inertiajs/react';
import { ArrowRight, Check, KeyRound, ShieldCheck } from 'lucide-react';
import { useRef } from 'react';
import SecurityController from '@/actions/App/Http/Controllers/Settings/SecurityController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { edit } from '@/routes/security';
/* @chisel-passkeys */
import type { Props as ManagePasskeysProps } from '@/components/manage-passkeys';
import ManagePasskeys from '@/components/manage-passkeys';
/* @end-chisel-passkeys */
/* @chisel-2fa */
import type { Props as ManageTwoFactorProps } from '@/components/manage-two-factor';
import ManageTwoFactor from '@/components/manage-two-factor';
/* @end-chisel-2fa */

// oxfmt-ignore
type Props = {
    passwordRules: string;
} /* @chisel-passkeys */ & ManagePasskeysProps /* @end-chisel-passkeys */ /* @chisel-2fa */ &
    ManageTwoFactorProps; /* @end-chisel-2fa */

export default function Security(props: Props) {
    const passwordInput = useRef<HTMLInputElement>(null);
    const currentPasswordInput = useRef<HTMLInputElement>(null);

    return (
        <>
            <Head title="Security settings" />

            <h1 className="sr-only">Security settings</h1>
            <section
                aria-labelledby="enrollment-heading"
                className="rounded-xl border border-white/10 bg-white/[.025] p-5"
            >
                <p className="mb-2 text-[10px] tracking-widest text-muted-foreground uppercase">
                    Workspace access ·{' '}
                    {Number(!!props.twoFactorEnabled) +
                        Number(!!props.passkeys?.length)}{' '}
                    of 2 steps complete
                </p>
                <h2 id="enrollment-heading" className="text-lg font-medium">
                    {props.twoFactorEnabled && props.passkeys?.length
                        ? 'Your account is ready'
                        : 'Secure your workspace'}
                </h2>
                <p className="mt-2 text-sm leading-6 text-muted-foreground">
                    Set up an authenticator and a passkey before entering
                    Trading. Both protect your account at sign-in.
                </p>
                <ol className="my-5 space-y-3">
                    <li>
                        <a
                            href="#authenticator-enrollment"
                            className="flex items-center gap-3 rounded-lg border border-white/10 p-3 hover:bg-white/5"
                        >
                            <span className="flex size-8 items-center justify-center rounded-full bg-white/5">
                                {props.twoFactorEnabled ? (
                                    <Check className="size-4 text-emerald-300" />
                                ) : (
                                    <ShieldCheck className="size-4" />
                                )}
                            </span>
                            <span className="min-w-0 flex-1">
                                <span className="block text-sm">
                                    1. Authenticator app
                                </span>
                                <span className="text-xs text-muted-foreground">
                                    {props.twoFactorEnabled
                                        ? 'Confirmed'
                                        : props.canManageTwoFactor
                                          ? 'Connect your app and confirm its code'
                                          : 'Enrollment is currently unavailable'}
                                </span>
                            </span>
                            <span className="text-xs text-muted-foreground">
                                {props.twoFactorEnabled
                                    ? 'Complete'
                                    : 'Required'}
                            </span>
                        </a>
                    </li>
                    <li>
                        <a
                            href="#passkey-enrollment"
                            className="flex items-center gap-3 rounded-lg border border-white/10 p-3 hover:bg-white/5"
                        >
                            <span className="flex size-8 items-center justify-center rounded-full bg-white/5">
                                {props.passkeys?.length ? (
                                    <Check className="size-4 text-emerald-300" />
                                ) : (
                                    <KeyRound className="size-4" />
                                )}
                            </span>
                            <span className="min-w-0 flex-1">
                                <span className="block text-sm">
                                    2. Passkey
                                </span>
                                <span className="text-xs text-muted-foreground">
                                    {props.passkeys?.length
                                        ? `${props.passkeys.length} registered`
                                        : props.canManagePasskeys
                                          ? 'Register this device or a security key'
                                          : 'Enrollment is currently unavailable'}
                                </span>
                            </span>
                            <span className="text-xs text-muted-foreground">
                                {props.passkeys?.length
                                    ? 'Complete'
                                    : 'Required'}
                            </span>
                        </a>
                    </li>
                </ol>
                <p className="text-xs leading-6 text-muted-foreground">
                    Keep your recovery codes somewhere safe, separate from this
                    device. They help you sign in if your authenticator is
                    unavailable.
                </p>
                {props.twoFactorEnabled && !!props.passkeys?.length && (
                    <Link href="/dashboard" className="hub-button primary mt-4">
                        Return to hub <ArrowRight className="size-4" />
                    </Link>
                )}
            </section>

            <section id="authenticator-enrollment" className="scroll-mt-6">
                <ManageTwoFactor
                    canManageTwoFactor={props.canManageTwoFactor}
                    requiresConfirmation={props.requiresConfirmation}
                    twoFactorEnabled={props.twoFactorEnabled}
                />
            </section>
            <section id="passkey-enrollment" className="scroll-mt-6">
                <ManagePasskeys
                    canManagePasskeys={props.canManagePasskeys}
                    passkeys={props.passkeys}
                />
            </section>

            <div className="space-y-6">
                <Heading
                    variant="small"
                    title="Update password"
                    description="Ensure your account is using a long, random password to stay secure"
                />

                <Form
                    {...SecurityController.update.form()}
                    options={{
                        preserveScroll: true,
                    }}
                    resetOnError={[
                        'password',
                        'password_confirmation',
                        'current_password',
                    ]}
                    resetOnSuccess
                    onError={(errors) => {
                        if (errors.password) {
                            passwordInput.current?.focus();
                        }

                        if (errors.current_password) {
                            currentPasswordInput.current?.focus();
                        }
                    }}
                    className="space-y-6"
                >
                    {({ errors, processing }) => (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor="current_password">
                                    Current password
                                </Label>

                                <PasswordInput
                                    id="current_password"
                                    ref={currentPasswordInput}
                                    name="current_password"
                                    className="mt-1 block w-full"
                                    autoComplete="current-password"
                                    placeholder="Current password"
                                />

                                <InputError message={errors.current_password} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="password">New password</Label>

                                <PasswordInput
                                    id="password"
                                    ref={passwordInput}
                                    name="password"
                                    className="mt-1 block w-full"
                                    autoComplete="new-password"
                                    placeholder="New password"
                                    passwordrules={props.passwordRules}
                                />

                                <InputError message={errors.password} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="password_confirmation">
                                    Confirm password
                                </Label>

                                <PasswordInput
                                    id="password_confirmation"
                                    name="password_confirmation"
                                    className="mt-1 block w-full"
                                    autoComplete="new-password"
                                    placeholder="Confirm password"
                                    passwordrules={props.passwordRules}
                                />

                                <InputError
                                    message={errors.password_confirmation}
                                />
                            </div>

                            <div className="flex items-center gap-4">
                                <Button
                                    disabled={processing}
                                    data-test="update-password-button"
                                >
                                    Save
                                </Button>
                            </div>
                        </>
                    )}
                </Form>
            </div>
        </>
    );
}

Security.layout = {
    breadcrumbs: [
        {
            title: 'Security settings',
            href: edit(),
        },
    ],
};
