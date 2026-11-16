<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Mail\MailManager;
use Illuminate\Support\ConfigurationUrlParser;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * @property CarbonInterface $expires_at
 * @property CarbonInterface|null $accepted_at
 */
class Invitation extends Model
{
    protected $guarded = [];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'accepted_at' => 'datetime'];
    }

    public static function issue(string $email, string $role, ?User $inviter = null): self
    {
        if (app()->isProduction()) {
            self::ensureProductionDelivery();
        }
        $token = Str::random(64);
        $invitation = self::create([
            'email' => strtolower($email), 'role' => $role, 'invited_by' => $inviter?->id,
            'token_hash' => hash('sha256', $token), 'expires_at' => now()->addDay(),
        ]);
        Mail::raw('Accept your Kernscale Trading invitation within 24 hours: '.route('invitations.show', ['token' => $token]), function ($message) use ($email) {
            $message->to($email)->subject('Your Kernscale Trading invitation');
        });

        return $invitation;
    }

    public static function ensureProductionDelivery(): void
    {
        $mailer = config('mail.mailers.'.config('mail.default'), []);
        if (! is_array($mailer) || config('mail.driver') !== null) {
            throw new \RuntimeException('Production mail transport configuration is unsupported.');
        }
        try {
            if (isset($mailer['url'])) {
                $mailer = (new ConfigurationUrlParser)->parseConfiguration($mailer);
                $mailer['transport'] = $mailer['driver'] ?? null;
            }
        } catch (\Throwable) {
            throw new \RuntimeException('Production mail transport configuration is invalid.');
        }
        $transport = $mailer['transport'] ?? null;
        $host = $mailer['host'] ?? null;
        $port = $mailer['port'] ?? null;
        $configured = match ($transport) {
            'smtp' => is_string($host) && $host !== '' && ! in_array(strtolower($host), ['localhost', '127.0.0.1', '::1'], true)
                && ! preg_match('/(?:^|\.)(?:example\.(?:com|org|net)|invalid|test|example)\z/i', $host)
                && filter_var($port, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]) !== false,
            'postmark' => filled(config('services.postmark.key')),
            'resend' => filled(config('services.resend.key')),
            'ses', 'ses-v2' => filled(config('services.ses.key')) && filled(config('services.ses.secret')) && filled(config('services.ses.region')),
            'mailgun' => filled(config('services.mailgun.domain')) && filled(config('services.mailgun.secret')),
            default => false,
        };
        $sender = config('mail.from.address');
        if (! $configured || ! is_string($sender) || ! filter_var($sender, FILTER_VALIDATE_EMAIL) || preg_match('/@example\.(?:com|org|net)\z/i', $sender)) {
            throw new \RuntimeException('Production invitations require configured direct SMTP/API delivery and a valid sender.');
        }
        try {
            // Resolve independently of fakes/cached mailers, without sending or connecting.
            (new MailManager(app()))->mailer();
        } catch (\Throwable) {
            throw new \RuntimeException('Production mail transport is unavailable or invalid.');
        }
    }
}
