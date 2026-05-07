pipeline {
    agent any

    stages {

        stage('Checkout') {
            steps {
                checkout scm
            }
        }

        stage('Build Images') {
            steps {
                sh 'docker build -t backend:latest ./backend'
                sh 'docker build -t frontend:latest ./frontend'
            }
        }

        stage('Verification syntaxe') {
            steps {
                sh '''docker run --rm backend:latest \
                  php -r "
                  \$files = glob('/var/www/app/Http/Controllers/*.php');
                  \$errors = 0;
                  foreach(\$files as \$f) {
                    exec('php -l '.\$f.' 2>&1', \$out, \$code);
                    if(\$code !== 0) {
                      echo implode(PHP_EOL, \$out).PHP_EOL;
                      \$errors++;
                    }
                  }
                  exit(\$errors);
                  "'''
            }
        }

        stage('Tests') {
            steps {
                sh 'docker network create test-net || true'
                sh '''docker run -d --name pg-test \
                  --network test-net \
                  -e POSTGRES_DB=testing \
                  -e POSTGRES_USER=postgres \
                  -e POSTGRES_PASSWORD=password \
                  postgres:16-alpine'''
                sh 'sleep 8'
                sh '''docker run --rm \
                  --network test-net \
                  -e APP_ENV=testing \
                  -e APP_KEY=base64:EQWSnPTutsgY10S8BrAIQs01aYQrjFs/5iTL1vymOWg= \
                  -e DB_CONNECTION=pgsql \
                  -e DB_HOST=pg-test \
                  -e DB_PORT=5432 \
                  -e DB_DATABASE=testing \
                  -e DB_USERNAME=postgres \
                  -e DB_PASSWORD=password \
                  backend:latest \
                  php vendor/bin/phpunit --testdox || true'''
            }
            post {
                always {
                    sh 'docker rm -f pg-test || true'
                    sh 'docker network rm test-net || true'
                }
            }
        }

        stage('Deploy K8s') {
            steps {
                sh 'kubectl apply -f k8s/namespace.yaml'
                sh 'kubectl apply -f k8s/secrets.yaml'
                sh 'kubectl apply -f k8s/configmap.yaml'
                sh 'kubectl apply -f k8s/postgres/postgres.yaml'
                sh 'kubectl apply -f k8s/backend/backend.yaml'
                sh 'kubectl apply -f k8s/frontend/frontend.yaml'
                sh 'kubectl apply -f k8s/ingress.yaml'
                sh 'kubectl rollout restart deployment/backend -n devops-app'
                sh 'kubectl rollout restart deployment/frontend -n devops-app'
            }
        }

        stage('Migrate') {
            steps {
                sh 'sleep 20'
                sh 'kubectl exec -n devops-app deployment/backend -c php-fpm -- php artisan migrate --force'
            }
        }
    }

    post {
        success {
            echo 'Deploiement reussi !'
        }
        failure {
            echo 'Echec du pipeline !'
        }
    }
}