<?php

namespace app\modules\admin\controllers;

use app\components\CategoryManager;
use app\components\stats\AccountDaily;
use app\components\stats\AccountDailyDiff;
use app\components\stats\AccountMonthlyDiff;
use app\components\updaters\AccountUpdater;
use app\models\AccountNote;
use app\models\Media;
use app\models\Tag;
use app\modules\admin\models\Account;
use app\modules\admin\models\AccountStats;
use Carbon\Carbon;
use Yii;
use yii\data\ActiveDataProvider;
use yii\filters\VerbFilter;
use yii\web\Controller;
use yii\web\NotFoundHttpException;
use yii2tech\csvgrid\CsvGrid;

/**
 * AccountController implements the CRUD actions for Account model.
 */
class AccountController extends Controller
{
    /**
     * @inheritdoc
     */
    public function behaviors()
    {
        return [
            'verbs' => [
                'class' => VerbFilter::class,
                'actions' => [
                    'delete' => ['POST'],
                    'delete-stats' => ['POST'],
                    'delete-associated' => ['POST'],
                    'categories' => ['POST'],
                    'update-note' => ['POST'],
                ],
            ],
        ];
    }

    public function actionDashboard($id)
    {
        $model = $this->findModel($id);

        $dailyDiff = Yii::createObject([
            'class' => AccountDailyDiff::class,
            'models' => $model,
        ]);
        $dailyDiff->initDiff(Carbon::now()->subMonth());
        $dailyChanges = $dailyDiff->getDiff($model->id);
        $dailyDiff->initLastDiff();
        $lastDailyChange = $dailyDiff->getLastDiff($model->id);


        $monthlyDiff = Yii::createObject([
            'class' => AccountMonthlyDiff::class,
            'models' => $model,
        ]);
        $monthlyDiff->initDiff(Carbon::now()->subYear());
        $monthlyChanges = $monthlyDiff->getDiff($model->id);
        $monthlyDiff->initLastDiff();
        $lastMonthlyChange = $monthlyDiff->getLastDiff($model->id);

        $dailyStats = Yii::createObject(AccountDaily::class, [$model]);
        $dailyStats->initData(Carbon::now()->subMonth());
        $dailyStats = $dailyStats->get();

        return $this->render('dashboard', [
            'model' => $model,
            'lastDailyChange' => current($lastDailyChange),
            'lastMonthlyChange' => current($lastMonthlyChange),
            'dailyStats' => $dailyStats,
            'dailyChanges' => $dailyChanges,
            'monthlyChanges' => $monthlyChanges,
        ]);
    }

    public function actionUpdateNote($id)
    {
        $model = $this->findModel($id);
        $user = Yii::$app->user;

        AccountNote::deleteAll([
            'account_id' => $model->id,
            'user_id' => $user->id,
        ]);

        $note = new AccountNote();
        $note->load(Yii::$app->request->post());
        $note->account_id = $model->id;
        $note->user_id = $user->id;
        $note->save();

        return $this->redirect(['account/dashboard', 'id' => $id]);
    }

    public function actionSettings($id)
    {
        $model = $this->findModel($id);
        $model->setScenario(Account::SCENARIO_UPDATE);

        if ($model->load(Yii::$app->request->post()) && $model->validate()) {
            if ($model->disabled) {
                $model->monitoring = 0;
                $model->save();
            } elseif ($model->is_valid) {
                $accountUpdater = Yii::createObject([
                    'class' => AccountUpdater::class,
                    'account' => $model,
                ]);
                $accountUpdater
                    ->setIsValid()
                    ->setNextStatsUpdate(null)
                    ->save();
            } else {
                $model->save();
            }

            return $this->redirect(['account/dashboard', 'id' => $model->id]);
        }

        return $this->render('settings', [
            'model' => $model,
        ]);
    }

    public function actionDeleteStats($id)
    {
        AccountStats::deleteAll(['account_id' => $id]);

        return $this->redirect(['account/dashboard', 'id' => $id]);
    }

    public function actionDeleteAssociated($id)
    {
        Media::deleteAll(['account_id' => $id]);

        return $this->redirect(['account/dashboard', 'id' => $id]);
    }

    public function actionDelete($id)
    {
        $this->findModel($id)->delete();

        return $this->redirect(['monitoring/accounts']);
    }

    public function actionCategories($id)
    {
        /** @var \app\models\User $identity */
        $identity = Yii::$app->user->identity;
        $model = $this->findModel($id);
        $tags = Yii::$app->request->post('account_tags', []);

        $manager = Yii::createObject(CategoryManager::class);
        $manager->saveForAccount($model, $tags, $identity);

        return $this->redirect(['account/dashboard', 'id' => $id]);
    }

    public function actionStats($id)
    {
        $model = $this->findModel($id);

        $dataProvider = new ActiveDataProvider([
            'query' => $model->getAccountStats(),
            'sort' => [
                'defaultOrder' => ['created_at' => SORT_DESC],
            ],
        ]);

        if (Yii::$app->request->get('export')) {
            $csv = new CsvGrid([
                'dataProvider' => $dataProvider,
                'columns' => [
                    'followed_by',
                    'follows',
                    'media',
                    'er',
                    'created_at',
                ],
            ]);

            return $csv->export()->send(sprintf('%s_stats_%s.csv', mb_strtolower($model->username), date('Y-m-d')));
        }

        return $this->render('stats', [
            'model' => $model,
            'dataProvider' => $dataProvider,
        ]);
    }

    public function actionMediaTags($id)
    {
        $model = $this->findModel($id);

        $dataProvider = new ActiveDataProvider([
            'query' => Tag::find()
                ->select([
                    'tag.*',
                    'count(tag.id) as occurs',
                ])
                ->innerJoin('media_tag', 'tag.id=media_tag.tag_id')
                ->innerJoin('media', 'media_tag.media_id=media.id')
                ->andWhere(['media.account_id' => $model->id])
                ->groupBy('tag.id'),
        ]);

        $dataProvider->sort->attributes['occurs'] = [
            'asc' => ['occurs' => SORT_ASC],
            'desc' => ['occurs' => SORT_DESC],
        ];
        $dataProvider->sort->defaultOrder = [
            'occurs' => SORT_DESC,
            'name' => SORT_ASC,
        ];

        if (Yii::$app->request->get('export')) {
            $csv = new CsvGrid([
                'dataProvider' => $dataProvider,
                'columns' => [
                    'name',
                    'occurs',
                ],
            ]);

            return $csv->export()->send(sprintf('%s_media-tags_%s.csv', mb_strtolower($model->username), date('Y-m-d')));
        }


        return $this->render('media-tags', [
            'model' => $model,
            'dataProvider' => $dataProvider,
        ]);
    }

    public function actionMediaAccounts($id)
    {
        $model = $this->findModel($id);

        $dataProvider = new ActiveDataProvider([
            'query' => Account::find()
                ->select([
                    'account.*',
                    'count(account.id) as occurs',
                ])
                ->innerJoin('media_account', 'account.id=media_account.account_id')
                ->innerJoin('media', 'media_account.media_id=media.id')
                ->andWhere(['media.account_id' => $model->id])
                ->groupBy('account.id'),
        ]);

        $dataProvider->sort->attributes['occurs'] = [
            'asc' => ['occurs' => SORT_ASC],
            'desc' => ['occurs' => SORT_DESC],
        ];
        $dataProvider->sort->defaultOrder = [
            'occurs' => SORT_DESC,
            'username' => SORT_ASC,
        ];

        if (Yii::$app->request->get('export')) {
            $csv = new CsvGrid([
                'dataProvider' => $dataProvider,
                'columns' => [
                    'username',
                    'occurs',
                ],
            ]);

            return $csv->export()->send(sprintf('%s_media-accounts_%s.csv', mb_strtolower($model->username), date('Y-m-d')));
        }

        /** @var \app\models\User $identity */
        $identity = Yii::$app->user->identity;
        $categoryManager = Yii::createObject(CategoryManager::class);
        $categories = $categoryManager->getForUserAccounts($identity, $model);

        return $this->render('media-accounts', [
            'model' => $model,
            'dataProvider' => $dataProvider,
            'categories' => $categories,
        ]);
    }


    /**
     * Finds the Account model based on its primary key value.
     * If the model is not found, a 404 HTTP exception will be thrown.
     *
     * @param integer $id
     * @return Account the loaded model
     * @throws NotFoundHttpException if the model cannot be found
     */
    public function findModel($id)
    {
        if (($model = Account::findOne($id)) !== null) {
            return $model;
        } else {
            throw new NotFoundHttpException('The requested page does not exist.');
        }
    }
}
