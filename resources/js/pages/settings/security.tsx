import { Form, Head, Link } from '@inertiajs/react';
import { ArrowRight, Check, KeyRound, ShieldCheck } from 'lucide-react';
import { useRef } from 'react';
import SecurityController from '@/actions/App/Http/Controllers/Settings/SecurityController';
import Heading from '@/components/heading';
import { StatusMark } from '@/components/kit/micro';
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
    const ready = !!props.twoFactorEnabled && !!props.passkeys?.length;

    return (
        <>
            <Head title="Security settings" />

            <h1 className="sr-only">Security settings</h1>
            <section
                aria-labelledby="enrollment-heading"
                className="rounded-xl border border-[var(--kit-line)] p-4"
            >
                <h2
                    id="enrollment-heading"
                    className="flex items-center gap-2 text-[15px] font-medium"
                >
                    <StatusMark status={ready ? 'done' : 'waiting'} />
                    {ready ? 'Account secured' : 'Secure your workspace'}
                    <span className="ml-auto text-[12px] font-normal text-[var(--kit-faint)] tabular-nums">
                        {Number(!!props.twoFactorEnabled) +
                            Number(!!props.passkeys?.length)}
                        /2
                    </span>
                </h2>
                {!ready && (
                    <p className="mt-1.5 text-[13px] text-[var(--kit-faint)]">
                        Set up an authenticator and a passkey before entering
                        Trading.
                    </p>
                )}
                <ol className="mt-4 space-y-2">
                    <li>
                        <a
                            href="#authenticator-enrollment"
                            className="flex items-center gap-3 rounded-lg p-2.5 transition-colors hover:bg-[var(--kit-hover)]"
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
                            className="flex items-center gap-3 rounded-lg p-2.5 transition-colors hover:bg-[var(--kit-hover)]"
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
                {!ready && (
                    <p className="mt-3 text-[12px] text-[var(--kit-faint)]">
                        Keep recovery codes somewhere safe, away from this
                        device.
                    </p>
                )}
                {ready && (
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
