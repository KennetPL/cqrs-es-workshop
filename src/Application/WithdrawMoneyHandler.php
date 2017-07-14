<?php
/**
 * Created by PhpStorm.
 * User: mkonopka
 * Date: 12.07.2017
 * Time: 09:25
 */

namespace Application;


use Domain\Account;
use Money\Currency;
use Money\Money;

class WithdrawMoneyHandler
{
    protected $accountRepository;

    /**
     * WithdrawMoneyHandler constructor.
     * @param $accountRepository
     */
    public function __construct($accountRepository)
    {
        $this->accountRepository = $accountRepository;
    }

    public function __invoke(WithdrawMoney $command)
    {
        /** @var Account $account */
        $account = $this->accountRepository->get($command->id());
        $account->withdraw(new Money($command->amount(), new Currency($command->currency())), $command->transactionTitle());
        $this->accountRepository->save($account);
    }

}