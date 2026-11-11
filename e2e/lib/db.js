// Accès à la base MySQL JETABLE des tests (conteneur Docker dédié) : jamais la base de développement.
import { execFileSync } from 'node:child_process';

function executer(requete) {
  const base = process.env.E2E_MYSQL_BASE ?? '';
  if (base !== 'creaself_e2e') throw new Error('Garde-fou : la base de test doit être creaself_e2e (variables E2E_* absentes ? lancez `npm run e2e`).');
  return execFileSync(
    'docker',
    ['exec', process.env.E2E_MYSQL_CONTENEUR, 'mysql', '-uroot', `-p${process.env.E2E_MYSQL_MDP}`, '--default-character-set=utf8mb4', base, '-N', '-B', '-e', requete],
    { encoding: 'utf8', stdio: ['ignore', 'pipe', 'ignore'] }, // stderr ignoré : avertissement « mot de passe en ligne de commande »
  );
}

/** Exécute une requête (ou plusieurs séparées par ;) et renvoie les lignes sous forme de tableaux de chaînes. */
export function lignes(requete) {
  return executer(requete).split('\n').filter(Boolean).map((l) => l.split('\t'));
}

/** Exécute une requête et renvoie la première valeur (ou null). */
export function valeur(requete) {
  const l = lignes(requete);
  return l.length ? l[0][0] : null;
}

/** Exécute une requête sans résultat attendu (UPDATE, DELETE...). */
export function executerSql(requete) {
  executer(requete);
}
