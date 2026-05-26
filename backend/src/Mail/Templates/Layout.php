<?php

declare(strict_types=1);

namespace Firol\Mail\Templates;

/**
 * Shared HTML wrapper for transactional emails.
 *
 * Email clients ignore most modern CSS, so the layout is built from
 * nested tables + inline styles. Gradient background and rounded
 * corners degrade gracefully (Outlook will render flat / square — fine).
 *
 * Tokens mirror frontend/src/index.css so the email feels like the app.
 */
final class Layout
{
    /**
     * @param string $title       short tag rendered above the headline
     * @param string $headline    H1 inside the card
     * @param string $bodyHtml    pre-rendered HTML (paragraphs, buttons, etc.)
     * @param string $preheader   short text visible in inbox previews
     */
    public static function render(string $title, string $headline, string $bodyHtml, string $preheader = ''): string
    {
        $title     = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
        $headline  = htmlspecialchars($headline, ENT_QUOTES, 'UTF-8');
        $preheader = htmlspecialchars($preheader, ENT_QUOTES, 'UTF-8');

        return <<<HTML
<!DOCTYPE html>
<html lang="sk">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>{$title}</title>
</head>
<body style="margin:0;padding:0;background-color:#f3f4f7;font-family:'Inter',system-ui,-apple-system,'Segoe UI',Roboto,sans-serif;color:#1f2733;-webkit-font-smoothing:antialiased;">
  <!-- Preheader (hidden) -->
  <div style="display:none;max-height:0;overflow:hidden;mso-hide:all;font-size:1px;line-height:1px;color:#f3f4f7;opacity:0;">{$preheader}</div>

  <!-- Backdrop — mirrors app radial gradient (warm red top-right, cool blue bottom-left) -->
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#f3f4f7;padding:40px 16px;">
    <tr>
      <td align="center">
        <!-- Card -->
        <table role="presentation" width="560" cellpadding="0" cellspacing="0" border="0" style="max-width:560px;width:100%;background:#ffffff;border-radius:20px;overflow:hidden;box-shadow:0 1px 2px rgba(15,23,42,0.06),0 12px 36px rgba(15,23,42,0.10);">

          <!-- Logo strip -->
          <tr>
            <td style="background:#ffffff;padding:24px 32px 20px 32px;border-bottom:1px solid #eef0f3;">
              <img src="https://app.poapp.sk/icons/firol_logo_color_transparent.png"
                   alt="Firol"
                   width="130"
                   height="44"
                   style="display:block;height:44px;width:auto;max-width:130px;border:0;outline:none;text-decoration:none;">
            </td>
          </tr>

          <!-- Header band — dark navy matching logo ink tones -->
          <tr>
            <td style="background:linear-gradient(135deg,#1a2340 0%,#253059 100%);padding:28px 32px 26px 32px;color:#ffffff;">
              <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                <tr>
                  <td style="font-size:12px;font-weight:600;letter-spacing:0.14em;text-transform:uppercase;color:rgba(255,255,255,0.55);">{$title}</td>
                </tr>
                <tr>
                  <td style="padding-top:10px;font-size:23px;font-weight:700;line-height:1.3;color:#ffffff;">{$headline}</td>
                </tr>
              </table>
            </td>
          </tr>

          <!-- Body -->
          <tr>
            <td style="padding:32px;font-size:15px;line-height:1.65;color:#2c3440;">
              {$bodyHtml}
            </td>
          </tr>

          <!-- Footer -->
          <tr>
            <td style="padding:18px 32px 26px 32px;border-top:1px solid #eef0f3;font-size:12px;line-height:1.6;color:#7a8494;">
              Firol &middot; SaaS pre revízie požiarnej ochrany<br>
              Tento email bol odoslaný automaticky, neodpovedaj naň.
            </td>
          </tr>
        </table>
      </td>
    </tr>
  </table>
</body>
</html>
HTML;
    }

    /** Pre-styled primary CTA button. URL is escaped by the caller. */
    public static function button(string $url, string $label): string
    {
        $url   = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
        $label = htmlspecialchars($label, ENT_QUOTES, 'UTF-8');
        return <<<HTML
<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:24px 0;">
  <tr>
    <td style="border-radius:14px;background:#E8433A;box-shadow:0 8px 20px rgba(232,67,58,0.28);">
      <a href="{$url}" style="display:inline-block;padding:14px 28px;font-size:15px;font-weight:600;color:#ffffff;text-decoration:none;border-radius:14px;">{$label}</a>
    </td>
  </tr>
</table>
HTML;
    }

    /** Monospaced fallback link box for clients that block buttons. */
    public static function linkFallback(string $url): string
    {
        $url = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
        return <<<HTML
<p style="margin:16px 0 0 0;font-size:13px;color:#5c6573;">Ak tlačidlo nefunguje, otvor v prehliadači:</p>
<p style="margin:6px 0 0 0;padding:10px 12px;background:#f5f6f8;border-radius:10px;font-family:'SFMono-Regular',Consolas,Menlo,monospace;font-size:12px;color:#2c3440;word-break:break-all;">{$url}</p>
HTML;
    }
}
