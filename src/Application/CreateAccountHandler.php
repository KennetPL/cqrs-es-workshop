<?php
/**
 * Created by PhpStorm.
 * User: mkonopka
 * Date: 12.07.2017
 * Time: 09:28
 */

namespace Application;


use Domain\Account;
use Domain\AccountRepository;

class CreateAccountHandler
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

    public function __invoke(CreateAccount $command)
    {
        $account = Account::new($command->id(), $command->currency());
        $this->accountRepository->save($account);
    }
}