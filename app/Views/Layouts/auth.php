<?php
declare(strict_types=1);

$titles = [
    'login' => ['Connexion', 'BON RETOUR', 'Connectez-vous à votre espace.'],
    'register' => ['Créer un compte', 'CRÉER MON ESPACE', 'Commencez simplement et créez votre exploitation.'],
    'forgot' => ['Mot de passe oublié', 'RÉCUPÉRATION DU COMPTE', 'Recevez un lien sécurisé pour choisir un nouveau mot de passe.'],
    'reset' => ['Nouveau mot de passe', 'SÉCURITÉ DU COMPTE', 'Choisissez un mot de passe unique pour votre compte.'],
    'verify' => ['Confirmer mon adresse', 'ACTIVATION DU COMPTE', 'Saisissez le code reçu par e-mail pour créer votre espace.'],
    'google-complete' => ['Finaliser la création du compte', 'INSCRIPTION GOOGLE', 'Quelques informations sont nécessaires avant la création de votre exploitation.'],
];
[$title, $eyebrow, $subtitle] = $titles[$view];
$escape = static fn (string $value): string => htmlspecialchars(
    $value,
    ENT_QUOTES | ENT_SUBSTITUTE,
    'UTF-8'
);
$value = static fn (string $key): string => $escape($values[$key] ?? '');
$invalid = isset($errors['form']);
$googlePendingProfile = $view === 'google-complete'
    && is_array($_SESSION['google_pending_profile'] ?? null)
    ? $_SESSION['google_pending_profile']
    : null;
$googleName = is_array($googlePendingProfile)
    && is_string($googlePendingProfile['name'] ?? null)
    ? trim($googlePendingProfile['name'])
    : '';
$googleNameMissing = is_array($googlePendingProfile)
    && ($googleName === '' || mb_strlen($googleName, 'UTF-8') > 120);
?>
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title><?= $escape($title) ?> — Mon Élevage</title>
    <link rel="stylesheet" href="assets/css/app.css">
    <link rel="stylesheet" href="assets/css/auth.css">
</head>
<body class="auth-body">
<header class="auth-topbar">
    <a class="brand auth-brand" href="auth.php?view=login" aria-label="Mon Élevage — connexion">
        <span class="brand-mark" aria-hidden="true">M</span>
        <span class="brand-name">Mon Élevage<small>GESTION AVICOLE</small></span>
    </a>
    <a class="auth-top-link" href="auth.php?view=login">Connexion</a>
</header>
<main class="auth-main">
    <section class="auth-card">
        <aside class="auth-art" aria-label="Présentation de Mon Élevage">
            <span class="auth-art-brand">MON ÉLEVAGE <small>GESTION AVICOLE</small></span>
            <div>
                <p class="auth-art-eyebrow">VOTRE ACTIVITÉ, EN UN SEUL ESPACE</p>
                <h2>Votre élevage, suivi en toute simplicité.</h2>
                <p>Un espace clair pour comprendre l’évolution de vos bandes et piloter votre activité.</p>
            </div>
            <div class="art-stat">
                <span aria-hidden="true">✓</span>
                <span><strong>Vos données restent privées.</strong><small>Chaque compte ne voit que ses exploitations autorisées.</small></span>
            </div>
        </aside>
        <section class="auth-panel auth-form-panel">
            <p class="auth-eyebrow"><?= $escape($eyebrow) ?></p>
            <h1><?= $escape($title) ?></h1>
            <p class="auth-subtitle"><?php if (is_array($googlePendingProfile)): ?>
                Compte Google sélectionné : <?= $escape((string) $googlePendingProfile['email']) ?>.
                Terminez les confirmations pour créer votre espace.
            <?php else: ?>
                <?= $escape($subtitle) ?>
            <?php endif; ?></p>

            <?php if (is_array($flash) && isset($flash['message'])): ?>
                <div class="form-message form-message-<?= $escape((string) ($flash['kind'] ?? 'success')) ?>"
                     role="status"><?= $escape((string) $flash['message']) ?></div>
            <?php endif; ?>
            <?php if ($invalid): ?>
                <div class="form-message form-message-error" role="alert">
                    <?= $escape($errors['form']) ?>
                </div>
            <?php endif; ?>

            <?php if ($view === 'login'): ?>
                <form method="post" action="auth.php?view=login" class="auth-form-content">
                    <input type="hidden" name="csrf_token" value="<?= $escape($csrfToken) ?>">
                    <label class="auth-field" for="email">Adresse e-mail
                        <input id="email" name="email" type="email" maxlength="254"
                               autocomplete="username" required autofocus
                               value="<?= $value('email') ?>"
                               aria-invalid="<?= $invalid ? 'true' : 'false' ?>">
                    </label>
                    <label class="auth-field" for="password">Mot de passe
                        <span class="auth-password-field">
                            <input id="password" name="password" type="password"
                                   autocomplete="current-password" required maxlength="72">
                            <button class="auth-password-toggle" type="button"
                                    data-password-toggle aria-controls="password"
                                    aria-label="Afficher le mot de passe" aria-pressed="false">
                                <svg class="auth-password-icon" viewBox="0 0 24 24"
                                     aria-hidden="true" focusable="false">
                                    <g class="auth-password-icon-visible">
                                        <path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12Z"></path>
                                        <circle cx="12" cy="12" r="3"></circle>
                                    </g>
                                    <g class="auth-password-icon-hidden">
                                        <path d="m3 3 18 18"></path>
                                        <path d="M10.6 5.2A10.8 10.8 0 0 1 12 5c6.4 0 10 7 10 7a15.7 15.7 0 0 1-3.1 3.8M6.2 6.2C3.5 8 2 12 2 12s3.6 7 10 7c1.3 0 2.5-.3 3.5-.8"></path>
                                        <path d="M9.9 9.9a3 3 0 0 0 4.2 4.2"></path>
                                    </g>
                                </svg>
                            </button>
                        </span>
                    </label>
                    <div class="auth-inline"><a href="auth.php?view=forgot">Mot de passe oublié ?</a></div>
                    <button class="button button-primary auth-submit" type="submit">Se connecter</button>
                </form>
                <div class="auth-divider"><span>OU</span></div>
                <a class="auth-google-button" href="google-auth.php?intent=login">
                    <svg viewBox="0 0 48 48" aria-hidden="true" focusable="false">
                        <path fill="#4285F4" d="M24 9.5c3.54 0 6.71 1.22 9.21 3.6l6.85-6.85C35.9 2.38 30.47 0 24 0 14.62 0 6.51 5.38 2.56 13.22l7.98 6.19C12.62 13.72 17.68 9.5 24 9.5z"/>
                        <path fill="#34A853" d="M46.98 24.55c0-1.57-.15-3.09-.38-4.55H24v9.02h12.94c-.58 2.96-2.26 5.48-4.76 7.18l7.73 6C44.42 37.93 46.98 31.84 46.98 24.55z"/>
                        <path fill="#FBBC05" d="M10.53 28.59a14.4 14.4 0 0 1-.76-4.59c0-1.59.27-3.13.76-4.59l-7.98-6.19A23.9 23.9 0 0 0 0 24c0 3.87.93 7.52 2.56 10.78l7.97-6.19z"/>
                        <path fill="#EA4335" d="M24 48c6.48 0 11.93-2.13 15.91-5.8l-7.73-6c-2.15 1.45-4.92 2.3-8.18 2.3-6.32 0-11.68-4.27-13.59-10.02l-7.98 6.19C6.51 42.62 14.62 48 24 48z"/>
                    </svg>
                    <span>Continuer avec Google</span>
                </a>
                <p class="auth-switch">Pas encore de compte ? <a href="auth.php?view=register">Créer un compte</a></p>

            <?php elseif ($view === 'register'): ?>
                <form method="post" action="auth.php?view=register" class="auth-form-content">
                    <input type="hidden" name="csrf_token" value="<?= $escape($csrfToken) ?>">
                    <label class="auth-field" for="full_name">Nom complet
                        <input id="full_name" name="full_name" type="text" maxlength="120"
                               autocomplete="name" required autofocus value="<?= $value('full_name') ?>">
                    </label>
                    <label class="auth-field" for="email">Adresse e-mail
                        <input id="email" name="email" type="email" maxlength="254"
                               autocomplete="email" required value="<?= $value('email') ?>">
                    </label>
                    <label class="auth-field" for="password">Mot de passe
                        <input id="password" name="password" type="password"
                               autocomplete="new-password" minlength="12" maxlength="72" required>
                        <small>12 caractères minimum. Évitez un mot de passe déjà utilisé.</small>
                    </label>
                    <label class="auth-field" for="farm_name">Nom de l’exploitation
                        <input id="farm_name" name="farm_name" type="text" maxlength="160"
                               autocomplete="organization" required value="<?= $value('farm_name') ?>">
                    </label>
                    <div class="auth-checkbox">
                        <input id="accept_terms" type="checkbox" name="accept_terms" value="1"
                               required <?= $consents['terms'] ? 'checked' : '' ?>>
                        <label for="accept_terms">
                                                       J’accepte les conditions d’utilisation.
                        </label>
                        <a href="legal.php?document=terms" target="_blank"
                           rel="noopener noreferrer">Lire</a>
                    </div>
                    <div class="auth-checkbox">
                        <input id="accept_privacy" type="checkbox" name="accept_privacy" value="1"
                                                          required <?= $consents['privacy'] ? 'checked' : '' ?>>
                        <label for="accept_privacy">
                                                       J’ai lu et j’accepte la politique de confidentialité.
                        </label>
                        <a href="legal.php?document=privacy" target="_blank"
                           rel="noopener noreferrer">Lire</a>
                    </div>
                    <div class="auth-checkbox">
                        <input id="confirm_farm_authorization" type="checkbox"
                                                          name="confirm_farm_authorization" value="1"
                                                          required <?= $consents['farm_authorization'] ? 'checked' : '' ?>>
                        <label for="confirm_farm_authorization">
                                                       Je confirme être autorisé(e) à créer cet espace et à utiliser le site
                                                       pour cette exploitation.
                        </label>
                    </div>
                    <p class="auth-legal-note">
                        Les trois confirmations sont obligatoires. Les versions acceptées, la
                        confirmation d’autorisation et la date sont enregistrées avec le compte.
                    </p>
                    <button class="button button-primary auth-submit" type="submit">Créer mon compte</button>
                </form>
                <div class="auth-divider"><span>OU</span></div>
                <a class="auth-google-button" href="google-auth.php?intent=register">
                    <svg viewBox="0 0 48 48" aria-hidden="true" focusable="false">
                        <path fill="#4285F4" d="M24 9.5c3.54 0 6.71 1.22 9.21 3.6l6.85-6.85C35.9 2.38 30.47 0 24 0 14.62 0 6.51 5.38 2.56 13.22l7.98 6.19C12.62 13.72 17.68 9.5 24 9.5z"/>
                        <path fill="#34A853" d="M46.98 24.55c0-1.57-.15-3.09-.38-4.55H24v9.02h12.94c-.58 2.96-2.26 5.48-4.76 7.18l7.73 6C44.42 37.93 46.98 31.84 46.98 24.55z"/>
                        <path fill="#FBBC05" d="M10.53 28.59a14.4 14.4 0 0 1-.76-4.59c0-1.59.27-3.13.76-4.59l-7.98-6.19A23.9 23.9 0 0 0 0 24c0 3.87.93 7.52 2.56 10.78l7.97-6.19z"/>
                        <path fill="#EA4335" d="M24 48c6.48 0 11.93-2.13 15.91-5.8l-7.73-6c-2.15 1.45-4.92 2.3-8.18 2.3-6.32 0-11.68-4.27-13.59-10.02l-7.98 6.19C6.51 42.62 14.62 48 24 48z"/>
                    </svg>
                    <span>Continuer avec Google</span>
                </a>
                <p class="auth-switch">Déjà inscrit ? <a href="auth.php?view=login">Se connecter</a></p>

            <?php elseif ($view === 'google-complete' && is_array($_SESSION['google_pending_profile'] ?? null)): ?>
                <form method="post" action="auth.php?view=google-complete" class="auth-form-content">
                    <input type="hidden" name="csrf_token" value="<?= $escape($csrfToken) ?>">
                    <?php if ($googleNameMissing): ?>
                        <label class="auth-field" for="full_name">Nom complet
                            <input id="full_name" name="full_name" type="text" maxlength="120"
                                   autocomplete="name" required autofocus value="<?= $value('full_name') ?>">
                        </label>
                    <?php endif; ?>
                    <label class="auth-field" for="farm_name">Nom de l’exploitation
                        <input id="farm_name" name="farm_name" type="text" maxlength="160"
                               autocomplete="organization" required <?= $googleNameMissing ? '' : 'autofocus' ?>
                               value="<?= $value('farm_name') ?>">
                    </label>
                    <div class="auth-checkbox">
                        <input id="accept_terms" type="checkbox" name="accept_terms" value="1"
                               required <?= $consents['terms'] ? 'checked' : '' ?>>
                        <label for="accept_terms">J’accepte les conditions d’utilisation.</label>
                        <a href="legal.php?document=terms" target="_blank" rel="noopener noreferrer">Lire</a>
                    </div>
                    <div class="auth-checkbox">
                        <input id="accept_privacy" type="checkbox" name="accept_privacy" value="1"
                               required <?= $consents['privacy'] ? 'checked' : '' ?>>
                        <label for="accept_privacy">J’ai lu et j’accepte la politique de confidentialité.</label>
                        <a href="legal.php?document=privacy" target="_blank" rel="noopener noreferrer">Lire</a>
                    </div>
                    <div class="auth-checkbox">
                        <input id="confirm_farm_authorization" type="checkbox"
                               name="confirm_farm_authorization" value="1"
                               required <?= $consents['farm_authorization'] ? 'checked' : '' ?>>
                        <label for="confirm_farm_authorization">
                            Je confirme être autorisé(e) à créer cet espace et à utiliser le site pour cette exploitation.
                        </label>
                    </div>
                    <p class="auth-legal-note">Votre compte et votre exploitation seront créés après ces confirmations.</p>
                    <button class="button button-primary auth-submit" type="submit">Créer mon compte</button>
                </form>
                <p class="auth-switch"><a href="auth.php?view=register">Annuler et revenir à l’inscription</a></p>

            <?php elseif ($view === 'forgot'): ?>
                <form method="post" action="auth.php?view=forgot" class="auth-form-content">
                    <input type="hidden" name="csrf_token" value="<?= $escape($csrfToken) ?>">
                    <label class="auth-field" for="email">Adresse e-mail
                        <input id="email" name="email" type="email" maxlength="254"
                               autocomplete="email" required autofocus value="<?= $value('email') ?>">
                    </label>
                    <button class="button button-primary auth-submit" type="submit">Envoyer le lien</button>
                </form>
                <p class="auth-switch"><a href="auth.php?view=login">Retour à la connexion</a></p>
                <p class="auth-legal-note">La réponse reste identique qu’un compte existe ou non, pour protéger sa confidentialité.</p>

            <?php elseif ($view === 'reset' && $tokenValid): ?>
                <form method="post" action="auth.php?view=reset" class="auth-form-content">
                    <input type="hidden" name="csrf_token" value="<?= $escape($csrfToken) ?>">
                    <input type="hidden" name="token" value="<?= $escape($token) ?>">
                    <label class="auth-field" for="password">Nouveau mot de passe
                        <input id="password" name="password" type="password"
                               autocomplete="new-password" minlength="12" maxlength="72" required autofocus>
                        <small>12 caractères minimum et 72 octets maximum.</small>
                    </label>
                    <label class="auth-field" for="password_confirmation">Confirmer le mot de passe
                        <input id="password_confirmation" name="password_confirmation" type="password"
                               autocomplete="new-password" minlength="12" maxlength="72" required>
                    </label>
                    <button class="button button-primary auth-submit" type="submit">Réinitialiser le mot de passe</button>
                </form>
            <?php elseif ($view === 'verify' && $tokenValid): ?>
                <form method="post" action="auth.php?view=verify" class="auth-form-content">
                    <input type="hidden" name="csrf_token" value="<?= $escape($csrfToken) ?>">
                    <input type="hidden" name="token" value="<?= $escape($token) ?>">
                    <p class="auth-inline-copy">Ce lien est valide. Confirmez votre adresse pour activer le compte et l’exploitation associée.</p>
                    <button class="button button-primary auth-submit" type="submit">Vérifier mon adresse e-mail</button>
                </form>
            <?php elseif ($view === 'verify' && $token === ''): ?>
                <form method="post" action="auth.php?view=verify" class="auth-form-content">
                    <input type="hidden" name="csrf_token" value="<?= $escape($csrfToken) ?>">
                    <label class="auth-field" for="email">Adresse e-mail
                        <input id="email" name="email" type="email" maxlength="254"
                               autocomplete="email" required autofocus value="<?= $value('email') ?>">
                    </label>
                    <label class="auth-field" for="code">Code de confirmation
                        <input id="code" name="code" type="text" inputmode="numeric"
                               pattern="[0-9]{6}" maxlength="6" autocomplete="one-time-code"
                               required aria-describedby="code-expiry">
                        <small id="code-expiry">Code à 6 chiffres, valable une minute.</small>
                    </label>
                    <input type="hidden" name="verification_action" value="confirm">
                    <button class="button button-primary auth-submit" type="submit">Confirmer et créer mon compte</button>
                </form>
                <form method="post" action="auth.php?view=verify" class="auth-form-content">
                    <input type="hidden" name="csrf_token" value="<?= $escape($csrfToken) ?>">
                    <input type="hidden" name="verification_action" value="resend">
                    <label class="auth-field" for="resend_email">Vous n’avez pas reçu le code ?
                        <input id="resend_email" name="email" type="email" maxlength="254"
                               autocomplete="email" required value="<?= $value('email') ?>">
                    </label>
                    <button class="button auth-submit" type="submit">Renvoyer un code</button>
                </form>
                <p class="auth-legal-note">Un code incorrect peut être saisi cinq fois au maximum. Demandez un nouveau code s’il a expiré.</p>
                <p class="auth-switch">Vous avez atteint la limite ? <a href="auth.php?view=register">Recommencer la création du compte</a></p>
            <?php else: ?>
                <p class="auth-switch"><a href="auth.php?view=forgot">Demander un nouveau lien</a></p>
                <p class="auth-switch"><a href="auth.php?view=login">Retour à la connexion</a></p>
            <?php endif; ?>
        </section>
    </section>
    <p class="auth-footer">© 2026 Mon Élevage · Espace sécurisé</p>
</main>
<?php if ($view === 'login'): ?>
    <script src="assets/js/auth.js" defer></script>
<?php endif; ?>
</body>
</html>
