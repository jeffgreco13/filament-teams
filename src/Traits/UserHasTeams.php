<?php

namespace JeffGreco13\FilamentTeams\Traits;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Config;
use JeffGreco13\FilamentTeams\Events\UserJoinedTeam;
use JeffGreco13\FilamentTeams\Events\UserLeftTeam;
use JeffGreco13\FilamentTeams\Exceptions\UserNotInTeamException;

trait UserHasTeams
{
    /**
     * Many-to-Many relations with the user model.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany
     */
    public function teams()
    {
        return $this->belongsToMany(
            config("filament-teams.team_model"),
            config("filament-teams.team_user_table"),
            "user_id",
            "team_id"
        )->withTimestamps();
    }
    /**
     * has-one relation with the current selected team model.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasOne
     */
    public function currentTeam()
    {
        return $this->hasOne(
            config("filament-teams.team_model"),
            "id",
            "current_team_id"
        );
    }

    /**
     * @return mixed
     */
    public function ownedTeams()
    {
        return $this->teams()->where("owner_id", "=", $this->getKey());
    }

    /**
     * One-to-Many relation with the invite model.
     * @return mixed
     */
    public function invites()
    {
        return $this->hasMany(
            config("filament-teams.invite_model"),
            "email",
            "email"
        );
    }

    /**
     * Boot the user model
     * Attach event listener to remove the many-to-many records when trying to delete
     * Will NOT delete any records if the user model uses soft deletes.
     *
     * @return void|bool
     */
    public static function bootUserHasTeams()
    {
        static::deleting(function (Model $user) {
            if (
                !method_exists(
                    config("filament-teams.user_model"),
                    "bootSoftDeletes"
                )
            ) {
                $user->teams()->sync([]);
            }

            return true;
        });
    }

    /**
     * Returns if the user owns a team.
     *
     * @return bool
     */
    public function isOwner()
    {
        return $this->teams()
            ->where("owner_id", "=", $this->getKey())
            ->first()
            ? true
            : false;
    }

    /**
     * Wrapper method for "isOwner".
     *
     * @return bool
     */
    public function isTeamOwner()
    {
        return $this->isOwner();
    }

    /**
     * @param $team
     * @return mixed
     */
    protected function retrieveTeamId($team)
    {
        if (is_object($team)) {
            $team = $team->getKey();
        }
        if (is_array($team) && isset($team["id"])) {
            $team = $team["id"];
        }

        return $team;
    }

    /**
     * Returns if the user owns the given team.
     *
     * @param mixed $team
     * @return bool
     */
    public function isOwnerOfTeam($team)
    {
        $team_id = $this->retrieveTeamId($team);

        return $this->teams()
            ->where("owner_id", $this->getKey())
            ->where("team_id", $team_id)
            ->first()
            ? true
            : false;
    }

    public function isOwnerOfCurrentTeam()
    {
        return $this->isOwnerOfTeam($this->currentTeam);
    }

    /**
     * Alias to eloquent many-to-many relation's attach() method.
     *
     * @param mixed $team
     * @param array $pivotData
     * @return $this
     */
    public function attachTeam($team, $pivotData = [])
    {
        $team = $this->retrieveTeamId($team);
        /*
         * If the user has no current team,
         * use the attached one
         */
        if (is_null($this->current_team_id)) {
            $this->current_team_id = $team;
            $this->save();

            if ($this->relationLoaded("currentTeam")) {
                $this->load("currentTeam");
            }
        }

        // Reload relation
        $this->load("teams");

        if (!$this->teams->contains($team)) {
            $this->teams()->attach($team, $pivotData);

            event(new UserJoinedTeam($this, $team));

            if ($this->relationLoaded("teams")) {
                $this->load("teams");
            }
        }

        return $this;
    }

    /**
     * Alias to eloquent many-to-many relation's detach() method.
     *
     * @param mixed $team
     * @return $this
     */
    public function detachTeam($team)
    {
        $team = $this->retrieveTeamId($team);
        $this->teams()->detach($team);

        event(new UserLeftTeam($this, $team));

        if ($this->relationLoaded("teams")) {
            $this->load("teams");
        }

        /*
         * If the user has no more teams,
         * unset the current_team_id
         */
        if ($this->teams()->count() === 0 || $this->current_team_id === $team) {
            $this->current_team_id = null;
            $this->save();

            if ($this->relationLoaded("currentTeam")) {
                $this->load("currentTeam");
            }
        }

        return $this;
    }

    /**
     * Attach multiple teams to a user.
     *
     * @param mixed $teams
     * @return $this
     */
    public function attachTeams($teams)
    {
        foreach ($teams as $team) {
            $this->attachTeam($team);
        }

        return $this;
    }

    /**
     * Detach multiple teams from a user.
     *
     * @param mixed $teams
     * @return $this
     */
    public function detachTeams($teams)
    {
        foreach ($teams as $team) {
            $this->detachTeam($team);
        }

        return $this;
    }

    /**
     * Switch the current team of the user.
     *
     * @param object|array|int $team
     * @return $this
     * @throws ModelNotFoundException
     * @throws UserNotInTeamException
     */
    public function switchTeam($team)
    {
        if ($team !== 0 && $team !== null) {
            $team = $this->retrieveTeamId($team);
            $teamModel = config("filament-teams.team_model");
            $teamObject = (new $teamModel())->find($team);
            if (!$teamObject) {
                $exception = new ModelNotFoundException();
                $exception->setModel($teamModel);
                throw $exception;
            }
            if (!$teamObject->users->contains($this->getKey())) {
                $exception = new UserNotInTeamException();
                $exception->setTeam($teamObject->name);
                throw $exception;
            }
        }
        $this->current_team_id = $team;
        $this->save();

        if ($this->relationLoaded("currentTeam")) {
            $this->load("currentTeam");
        }

        return $this;
    }
}
