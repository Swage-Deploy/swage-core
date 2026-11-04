<?php

namespace App\Enums;

use App\Models\Certificate;
use App\Models\User;

/**
 * What a notification is about. Each person chooses which types also reach them by email.
 */
enum NotificationType: string
{
    case DeploymentFailed = 'deployment_failed';
    case ProvisioningFailed = 'provisioning_failed';
    case HostKeyChanged = 'host_key_changed';
    case CertificateRenewalOverdue = 'certificate_renewal_overdue';
    case RebootRequired = 'reboot_required';
    case ServerAlert = 'server_alert';
    case UpdateFailed = 'update_failed';

    public function label(): string
    {
        return match ($this) {
            self::DeploymentFailed => __('Deployment failed'),
            self::ProvisioningFailed => __('Provisioning failed'),
            self::HostKeyChanged => __('SSH host key changed'),
            self::CertificateRenewalOverdue => __('Certificate not renewed'),
            self::RebootRequired => __('Reboot required'),
            self::ServerAlert => __('Plugin alerts'),
            self::UpdateFailed => __('Swage update failed'),
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::DeploymentFailed => __('A deployment of a site you manage failed.'),
            self::ProvisioningFailed => __('Provisioning stopped with an error.'),
            self::HostKeyChanged => __('A server\'s SSH host key no longer matches, so Swage stopped connecting to it.'),
            self::CertificateRenewalOverdue => __('A certificate has under :days days left, so certbot failed to renew it.', ['days' => Certificate::RENEWAL_OVERDUE_DAYS]),
            self::RebootRequired => __('An update needs a reboot to take effect.'),
            self::ServerAlert => __('A plugin raised an alert, such as the security scanner finding an attack.'),
            self::UpdateFailed => __('Updating Swage failed or was rolled back.'),
        };
    }

    /**
     * Whether only administrators receive it, because it is about the panel rather than a server.
     */
    public function isForAdministrators(): bool
    {
        return $this === self::UpdateFailed;
    }

    /**
     * The types a user can receive.
     *
     * @return list<self>
     */
    public static function for(User $user): array
    {
        return array_values(array_filter(self::cases(), fn (self $type): bool => $user->is_admin || ! $type->isForAdministrators()));
    }
}
