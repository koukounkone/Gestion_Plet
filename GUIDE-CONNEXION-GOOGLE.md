# Configurer la connexion Google

La connexion utilise OAuth 2.0 et OpenID Connect. Le site ne reçoit jamais le
mot de passe Google. Il vérifie le jeton d’identité côté serveur, son audience,
son émetteur, sa signature, son expiration, son nonce et l’adresse vérifiée.

## Google Cloud

1. Créez ou sélectionnez un projet dans Google Cloud Console.
2. Configurez l’écran de consentement OAuth et ajoutez les utilisateurs de test
   si l’application est encore en mode test.
3. Créez un identifiant OAuth de type **Application Web**.
4. Ajoutez l’origine du site dans les origines JavaScript autorisées. En local,
   utilisez par exemple `http://localhost:8080`.
5. Ajoutez l’URI de rappel exacte dans les URI de redirection autorisés :
   `http://localhost:8080/google-callback.php` en local ou
   `https://votre-domaine/google-callback.php` en production.

Google exige HTTPS hors des URI locales autorisées. L’hôte, le port et le chemin
doivent correspondre exactement entre Google Cloud et la configuration du site.

## Variables du site

Définissez ces variables dans l’environnement du processus Apache/PHP :

- `MON_ELEVAGE_GOOGLE_CLIENT_ID`
- `MON_ELEVAGE_GOOGLE_CLIENT_SECRET`
- `MON_ELEVAGE_GOOGLE_REDIRECT_URI`

La dernière valeur doit être l’URI complète de rappel déclarée chez Google.
Redémarrez Apache après avoir ajouté les variables. Ne placez pas le secret dans
le dossier public, dans un dépôt Git, ni dans un message ou une capture.

Sur une base existante, sauvegardez-la puis appliquez
`database/migrations/018_create_oauth_identities.sql`. Une installation neuve
créée à partir de `database/schema.sql` contient déjà la table.

## Création et liaison de comptes

Un nouvel utilisateur doit fournir le nom de son exploitation et accepter les
conditions, la politique de confidentialité et la confirmation d’autorisation
avant que son compte soit créé. Le nom et l’adresse vérifiée fournis par Google
sont repris automatiquement ; un nom n’est demandé sur l’écran de finalisation
que si Google ne l’a pas fourni ou s’il dépasse la longueur acceptée par le site.
L’adresse Google n’est jamais redemandée. La connexion Google ne lie jamais
automatiquement un compte classique en se fiant uniquement à l’adresse.

Pour lier un compte classique, connectez-vous d’abord avec ce compte, ouvrez le
profil, puis choisissez **Lier mon compte Google**. Google doit confirmer la
même adresse e-mail que celle du compte Mon Élevage.

## Vérification

Les contrôles locaux couvrent le format, la signature et les claims OIDC avec des
jetons de test. Pour confirmer l’échange OAuth réel, configurez les identifiants
Google, puis testez dans un navigateur avec un compte contrôlé : sélection,
annulation, nouvelle inscription avec consentement, connexion récurrente et
liaison d’un compte classique. Ne déclarez pas la connexion opérationnelle avant
ces tests contre Google.
