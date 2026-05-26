pipeline {
    agent any

    // ============================================================
    // VARIABLES D'ENVIRONNEMENT
    // ============================================================
    // Centralisées ici pour faciliter la maintenance
    // Une seule modification ici suffit pour tout le pipeline
    environment {
        // Namespace Kubernetes cible
        K8S_NAMESPACE = 'devops-app'

        // Noms des images Docker
        BACKEND_IMAGE  = 'backend'
        FRONTEND_IMAGE = 'frontend'

        // Version des images — utilise le numéro de build Jenkins
        // Chaque build a une version unique
        // Evite d'écraser l'image précédente avec :latest
        // En cas de problème on peut rollback vers une version précédente
        IMAGE_TAG = "v${BUILD_NUMBER}"

        // Timeout pour kubectl rollout status
        ROLLOUT_TIMEOUT = '120s'
    }

    stages {

        // ============================================================
        // STAGE 1 — Checkout
        // ============================================================
        // Récupère le code source depuis Git
        // scm = Source Control Management configuré dans Jenkins
        stage('Checkout') {
            steps {
                echo "Récupération du code source..."
                checkout scm
            }
        }

        // ============================================================
        // STAGE 2 — Build des images Docker
        // ============================================================
        // On build les deux images en parallèle pour gagner du temps
        // parallel = les deux builds tournent en même temps
        stage('Build Images') {
            steps {
                echo "Build des images Docker..."
                parallel(
                    // Build backend
                    "Backend": {
                        sh """
                            docker build \
                                -t ${BACKEND_IMAGE}:${IMAGE_TAG} \
                                -t ${BACKEND_IMAGE}:latest \
                                ./backend
                        """
                        // On tag avec IMAGE_TAG ET latest
                        // IMAGE_TAG pour le versioning
                        // latest pour la commodité en dev
                    },
                    // Build frontend
                    "Frontend": {
                        sh """
                            docker build \
                                -t ${FRONTEND_IMAGE}:${IMAGE_TAG} \
                                -t ${FRONTEND_IMAGE}:latest \
                                ./frontend
                        """
                    }
                )
            }
        }

        // ============================================================
        // STAGE 3 — Vérification syntaxe PHP
        // ============================================================
        // php -l vérifie la syntaxe sans exécuter le code
        // Détecte les erreurs de syntaxe PHP avant le déploiement
        stage('Verification Syntaxe PHP') {
            steps {
                echo "Vérification de la syntaxe PHP..."
                sh """
                    docker run --rm ${BACKEND_IMAGE}:${IMAGE_TAG} \
                        bash -c "
                            find /var/www/app -name '*.php' \
                            | xargs -I{} php -l {} \
                            | grep -v 'No syntax errors' \
                            || true
                        "
                """
                // || true = ne pas faire échouer le stage si grep
                // ne trouve rien (grep retourne 1 si aucun résultat)
            }
        }

        // ============================================================
        // STAGE 4 — Tests PHPUnit
        // ============================================================
        // On crée un réseau Docker isolé pour les tests
        // Un container Postgres de test est lancé
        // Les tests tournent contre cette DB de test
        // La DB de prod n'est jamais touchée
        stage('Tests') {
            steps {
                echo "Lancement des tests PHPUnit..."
                sh """
                    # Créer le réseau de test isolé
                    docker network create test-net-${BUILD_NUMBER} || true

                    # Lancer Postgres pour les tests
                    docker run -d \
                        --name pg-test-${BUILD_NUMBER} \
                        --network test-net-${BUILD_NUMBER} \
                        -e POSTGRES_DB=testing \
                        -e POSTGRES_USER=postgres \
                        -e POSTGRES_PASSWORD=postgres_test \
                        postgres:15-alpine
                """
                // BUILD_NUMBER dans le nom du container et du réseau
                // Permet plusieurs builds en parallèle sans conflits

                // Attendre que Postgres soit prêt
                // pg_isready est plus fiable que sleep
                sh """
                    echo "Attente que Postgres soit prêt..."
                    docker run --rm \
                        --network test-net-${BUILD_NUMBER} \
                        --entrypoint sh \
                        postgres:15-alpine \
                        -c "
                            until pg_isready -h pg-test-${BUILD_NUMBER} -U postgres; do
                                echo 'Postgres pas encore prêt, attente...'
                                sleep 2
                            done
                            echo 'Postgres est prêt !'
                        "
                """
                // pg_isready au lieu de sleep 8
                // sleep 8 = on attend 8s même si Postgres est prêt en 2s
                // pg_isready = on attend exactement le temps nécessaire

                // Lancer les tests
                sh """
                    docker run --rm \
                        --network test-net-${BUILD_NUMBER} \
                        -e APP_ENV=testing \
                        -e APP_KEY=base64:EQWSnPTutsgY10S8BrAIQs01aYQrjFs/5iTL1vymOWg= \
                        -e DB_CONNECTION=pgsql \
                        -e DB_HOST=pg-test-${BUILD_NUMBER} \
                        -e DB_PORT=5432 \
                        -e DB_DATABASE=testing \
                        -e DB_USERNAME=postgres \
                        -e DB_PASSWORD=postgres_test \
                        ${BACKEND_IMAGE}:${IMAGE_TAG} \
                        php vendor/bin/phpunit --testdox
                """
                // Note : APP_KEY et DB_PASSWORD sont encore en clair ici
                // La prochaine étape sera de les mettre dans
                // Jenkins Credentials Store
            }

            // Nettoyage après les tests
            // always = s'exécute même si les tests échouent
            post {
                always {
                    echo "Nettoyage des containers de test..."
                    sh """
                        docker rm -f pg-test-${BUILD_NUMBER} || true
                        docker network rm test-net-${BUILD_NUMBER} || true
                    """
                }
            }
        }

        // ============================================================
        // STAGE 5 — Déploiement Kubernetes
        // ============================================================
        // On applique tous les manifests K8s dans le bon ordre
        // kubectl apply = crée si inexistant, met à jour si existant
        stage('Deploy K8s') {
            steps {
                echo "Déploiement sur Kubernetes..."

                // 1. ConfigMap et Secrets — les apps en ont besoin
                // NOTE : namespace et RBAC sont appliqués UNE SEULE FOIS
                // manuellement (kubectl apply -f k8s/namespace.yaml et rbac.yaml)
                // car namespace = ressource cluster-scoped hors portée du Role Jenkins
                sh 'kubectl apply -f k8s/configmap.yaml'
                sh 'kubectl apply -f k8s/secrets.yaml'

                // 2. Base de données
                sh 'kubectl apply -f k8s/postgres/postgres.yaml'

                // 3. Attendre que Postgres soit prêt
                sh """
                    kubectl rollout status deployment/postgres \
                        -n ${K8S_NAMESPACE} \
                        --timeout=${ROLLOUT_TIMEOUT}
                """

                // 4. Mettre à jour le tag d'image dans les manifests
                // sed remplace le tag hardcodé par la version du build en cours
                // Un seul rollout, directement avec la bonne image
                sh """
                    sed -i 's|image: ${BACKEND_IMAGE}:.*|image: ${BACKEND_IMAGE}:${IMAGE_TAG}|g' k8s/backend/backend.yaml
                    sed -i 's|image: ${BACKEND_IMAGE}:.*|image: ${BACKEND_IMAGE}:${IMAGE_TAG}|g' k8s/backend/migrate-job.yaml
                    sed -i 's|image: ${FRONTEND_IMAGE}:.*|image: ${FRONTEND_IMAGE}:${IMAGE_TAG}|g' k8s/frontend/frontend.yaml
                """

                // 5. Backend et Frontend — déjà avec le bon tag d'image
                sh 'kubectl apply -f k8s/backend/backend.yaml'
                sh 'kubectl apply -f k8s/frontend/frontend.yaml'

                // 6. Ingress en dernier
                sh 'kubectl apply -f k8s/ingress.yaml'
            }
        }

        // ============================================================
        // STAGE 6 — Attente que les pods soient prêts
        // ============================================================
        // kubectl rollout status attend que le déploiement
        // soit complètement terminé avant de continuer
        // Remplace le sleep 20 qui était arbitraire et non fiable
        stage('Attente Disponibilite') {
            steps {
                echo "Attente que les pods soient prêts..."

                // Attendre le backend
                sh """
                    kubectl rollout status deployment/backend \
                        -n ${K8S_NAMESPACE} \
                        --timeout=${ROLLOUT_TIMEOUT}
                """
                // rollout status surveille les readinessProbe
                // Il ne continue que quand TOUS les pods sont Ready
                // Timeout de 120s = échec si pas prêt après 2 minutes

                // Attendre le frontend
                sh """
                    kubectl rollout status deployment/frontend \
                        -n ${K8S_NAMESPACE} \
                        --timeout=${ROLLOUT_TIMEOUT}
                """
            }
        }

        // ============================================================
        // STAGE 7 — Migrations Laravel
        // ============================================================
        // Les migrations s'exécutent APRÈS que les pods soient prêts
        // On est certain que le backend est disponible
        // Remplace sleep 20 + migrate qui était non fiable
        stage('Migrate') {
            steps {
                echo "Exécution des migrations Laravel..."
                // Utilise un Job K8s dédié plutôt que kubectl exec :
                // - attend que Postgres soit prêt avant de lancer
                // - retente automatiquement en cas d'échec (backoffLimit: 2)
                // - traçable avec : kubectl get jobs -n devops-app
                sh """
                    kubectl delete job backend-migrate \
                        -n ${K8S_NAMESPACE} --ignore-not-found
                    kubectl apply -f k8s/backend/migrate-job.yaml
                    kubectl wait --for=condition=complete \
                        --timeout=${ROLLOUT_TIMEOUT} \
                        job/backend-migrate \
                        -n ${K8S_NAMESPACE}
                """
            }
        }
    }

    // ============================================================
    // POST — Actions après le pipeline
    // ============================================================
    post {
        success {
            echo """
                ✅ Déploiement réussi !
                Image backend  : ${BACKEND_IMAGE}:${IMAGE_TAG}
                Image frontend : ${FRONTEND_IMAGE}:${IMAGE_TAG}
                Namespace      : ${K8S_NAMESPACE}
            """
        }
        failure {
            echo """
                ❌ Echec du pipeline !
                Vérifier les logs ci-dessus pour plus de détails.
                Namespace : ${K8S_NAMESPACE}
            """
            // Ici on pourrait ajouter des notifications
            // Slack, email, etc.
        }
        always {
            // Nettoyage des images Docker non utilisées
            // pour éviter de saturer le disque du node Jenkins
            sh 'docker image prune -f || true'
        }
    }
}