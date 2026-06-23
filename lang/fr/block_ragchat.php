<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Chaînes de traduction françaises pour block_ragchat.
 *
 * @package    block_ragchat
 * @copyright  2026 Your Name <your@email.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

// Plugin.
$string['pluginname'] = 'Chatbot IA';

// Interface chat.
$string['chat_placeholder']  = 'Posez une question sur les formations disponibles…';
$string['chat_send']         = 'Envoyer';
$string['chat_thinking']     = 'Réflexion en cours…';
$string['ai_disclaimer']     = 'Réponse générée par IA — vérifiez toujours les sources.';
$string['sources']           = 'Sources';
$string['no_results']        = 'Aucun cours correspondant à votre question n\'a été trouvé. Essayez de reformuler.';
$string['unknown_source']      = 'Source inconnue';
$string['chat_user_question']    = 'Question :';
$string['history_heading']       = 'Historique de la conversation :';
$string['history_role_user']     = 'Utilisateur';
$string['history_role_assistant']= 'Assistant';
$string['norag_disclaimer']    = 'Catalogue non encore indexé — réponse sans contexte de cours.';

$string['systemprompt_norag']  = 'Tu es un assistant sur une plateforme de formation. Le catalogue des cours n\'est pas encore indexé. Réponds à la question de l\'utilisateur du mieux que tu peux avec tes connaissances générales. Sois honnête si tu ne sais pas.';

// Erreurs.
$string['error_generic']       = 'Une erreur est survenue. Veuillez réessayer ultérieurement.';
$string['error_emptyquestion'] = 'Veuillez saisir une question.';
$string['error_api']           = 'Erreur Albert API : {$a}';
$string['provider_not_configured'] = 'Le fournisseur IA n\'est pas configuré. Contactez votre administrateur.';

// Prompts système.
$string['systemprompt_catalogue'] = "Tu es un assistant d'orientation pour une plateforme de formation.
Aide l'utilisateur à trouver des cours correspondant à ses besoins.
Réponds UNIQUEMENT à partir des fiches de cours fournies ci-dessous.
Si aucun cours ne correspond, dis-le clairement et propose des thèmes proches.
Présente toujours les cours avec leur titre, leur description et le nom de l'enseignant si disponible.

Cours disponibles :
{chunks}";

$string['systemprompt_course'] = "Tu es un assistant pédagogique pour le cours « {coursename} ».
Réponds UNIQUEMENT à partir des extraits de documents fournis ci-dessous.
Si la réponse n'est pas dans les documents, dis-le clairement.
Ne réponds jamais aux questions portant sur les évaluations ou les examens.
Cite toujours le document source de ta réponse.

Documents disponibles :
{chunks}";

// Paramètres — prompts par défaut.
$string['settings_prompts_heading']              = 'Prompts système par défaut';
$string['settings_prompts_heading_desc']         = 'Ces prompts s\'appliquent à tout le site sauf s\'ils sont surchargés sur une instance du bloc. Laisser vide pour utiliser le prompt intégré.';
$string['settings_systemprompt_catalogue']       = 'Prompt chatbot catalogue';
$string['settings_systemprompt_catalogue_desc']  = 'Prompt système utilisé en page d\'accueil / catalogue. Utiliser {chunks} pour le contexte des cours.';
$string['settings_systemprompt_course']          = 'Prompt chatbot cours';
$string['settings_systemprompt_course_desc']     = 'Prompt système utilisé dans un cours. Utiliser {chunks} pour les documents et {coursename} pour le nom du cours.';
$string['settings_systemprompt_norag']           = 'Prompt sans RAG';
$string['settings_systemprompt_norag_desc']      = 'Prompt utilisé quand la collection n\'a pas encore été indexée (cron non exécuté).';

// Formulaire d'édition de l'instance.
$string['editform_header']           = 'Configuration du chatbot';
$string['editform_title']            = 'Titre du bloc';
$string['editform_prompt_header']    = 'Prompt système (surcharge le paramètre global)';
$string['editform_systemprompt']     = 'Prompt personnalisé';
$string['editform_systemprompt_help'] = 'Laisser vide pour utiliser le prompt global ou le prompt intégré. Utiliser {chunks} pour le contexte et {coursename} pour le nom du cours.';
$string['editform_systemprompt_hint'] = 'Variables disponibles : <code>{chunks}</code> (contexte récupéré), <code>{coursename}</code> (nom du cours, mode cours uniquement).';

// Paramètres.
$string['settings_apikey']              = 'Clé API Albert (secours)';
$string['settings_apikey_desc']         = 'Utilisée uniquement si le plugin aiprovider_albertapi n\'est pas installé.';
$string['settings_apiendpoint']         = 'Endpoint Albert API (secours)';
$string['settings_apiendpoint_desc']    = 'URL de base de l\'API Albert. Par défaut : instance de production DINUM.';
$string['settings_embedding_model']     = 'Modèle d\'embeddings';
$string['settings_embedding_model_desc']= 'Modèle Albert utilisé pour vectoriser les documents et les questions.';
$string['settings_search_top_k']        = 'Chunks récupérés (top-K)';
$string['settings_search_top_k_desc']   = 'Nombre de chunks récupérés via Albert /v1/search avant le reranking.';
$string['settings_rerank_top_n']        = 'Chunks après reranking (top-N)';
$string['settings_rerank_top_n_desc']   = 'Nombre de chunks injectés dans le contexte du LLM après /v1/rerank.';
$string['settings_sync_heading']        = 'Synchronisation du catalogue';
$string['settings_last_sync']           = 'Dernière synchronisation : {$a}';
$string['never']                        = 'jamais';

// Capabilities.
$string['block_ragchat:addinstance']   = 'Ajouter le bloc chatbot RAG';
$string['block_ragchat:myaddinstance'] = 'Ajouter le chatbot RAG au tableau de bord';
$string['block_ragchat:use']           = 'Utiliser le chatbot RAG';
$string['block_ragchat:manage']        = 'Gérer les paramètres et les journaux du chatbot RAG';

// Tâche planifiée.
$string['task_sync_catalogue'] = 'Synchroniser le catalogue de cours vers l\'API Albert';
