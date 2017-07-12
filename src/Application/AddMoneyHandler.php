<?php
/**
 * Created by PhpStorm.
 * User: mkonopka
 * Date: 12.07.2017
 * Time: 08:16
 */

namespace Application;


use Domain\AccountRepository;
use Money\Currency;
use Money\Money;

class AddMoneyHandler
{
    /** @var  AccountRepository */
    private $accountRepository;

    /**
     * AddMoneyHandler constructor.
     * @param AccountRepository $accountRepository
     */
    public function __construct(AccountRepository $accountRepository)
    {
        $this->accountRepository = $accountRepository;
    }

    public function __invoke(AddMoney $command)
    {
        $account = $this->accountRepository->get($command->id());

        $account->add(new Money($command->amount(), new Currency($command->currency())));

        $this->accountRepository->save($account);
    }

}